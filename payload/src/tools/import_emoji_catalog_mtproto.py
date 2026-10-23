#!/usr/bin/env python3
"""Import Telegram custom emoji catalog pages from a source bot into MySQL.

All persistent credentials come from GitPosted's single protected config.php.
Only one-time login codes may be supplied through the process environment.
Runtime Telegram session files are stored outside the website root.
"""

from __future__ import annotations

import argparse
import asyncio
import json
import os
import re
import subprocess
import sys
from functools import lru_cache
from pathlib import Path
from typing import Any, Sequence
from urllib.parse import urlsplit

import pymysql
from telethon import TelegramClient
from telethon.errors import SessionPasswordNeededError
from telethon.tl.types import Message, MessageEntityTextUrl


ROOT = Path(__file__).resolve().parents[1]
CONFIG_PATH = Path(
    os.environ.get(
        "POSTED_CONFIG_FILE",
        "/etc/gitposted/config.php",
    )
)


@lru_cache(maxsize=1)
def app_config() -> dict[str, Any]:
    if not CONFIG_PATH.is_file():
        raise RuntimeError(f"GitPosted config is unavailable: {CONFIG_PATH}")
    php = (
        "$config=require $argv[1];"
        "if(!is_array($config)){fwrite(STDERR,'invalid config');exit(2);}"
        "echo json_encode($config,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);"
    )
    process = subprocess.run(
        ["php", "-r", php, str(CONFIG_PATH)],
        check=False,
        capture_output=True,
        text=True,
        timeout=10,
    )
    if process.returncode != 0:
        raise RuntimeError("Unable to load the protected GitPosted config")
    decoded = json.loads(process.stdout)
    if not isinstance(decoded, dict):
        raise RuntimeError("GitPosted config must be an object")
    return decoded


def config_value(
    section: str,
    key: str,
    default: Any = None,
    *,
    allow_empty: bool = False,
) -> Any:
    value = app_config().get(section, {})
    if not isinstance(value, dict):
        raise RuntimeError(f"Config section {section} is invalid")
    result = value.get(key, default)
    if result is None or (result == "" and not allow_empty):
        raise RuntimeError(f"Config value {section}.{key} is required")
    return result


@lru_cache(maxsize=1)
def data_directory() -> Path:
    configured = os.environ.get("POSTED_DATA_DIR")
    if configured is None or configured.strip() == "":
        configured = app_config().get("data_dir")
    if not isinstance(configured, str) or configured.strip() == "":
        raise RuntimeError("Top-level config value data_dir is required")
    path = Path(configured.strip())
    if not path.is_absolute():
        raise RuntimeError("GitPosted data_dir must be an absolute path")
    return path


def tmp_directory() -> Path:
    return data_directory() / "tmp"


def session_path() -> Path:
    return tmp_directory() / "posted_mtproto"


def phone_code_hash_path() -> Path:
    return tmp_directory() / "posted_phone_code_hash"


def db_connection() -> pymysql.connections.Connection:
    return pymysql.connect(
        host=str(config_value("database", "host", "localhost")),
        user=str(config_value("database", "user", "posted")),
        password=str(config_value("database", "password")),
        database=str(config_value("database", "name", "posted")),
        charset="utf8mb4",
        autocommit=False,
    )


def client() -> TelegramClient:
    tmp_directory().mkdir(parents=True, exist_ok=True)
    return TelegramClient(
        str(session_path()),
        int(config_value("mtproto", "api_id")),
        str(config_value("mtproto", "api_hash")),
        connection_retries=1,
        request_retries=1,
        timeout=20,
    )


async def send_code() -> None:
    phone = str(config_value("mtproto", "phone"))
    tg = client()
    await tg.connect()
    try:
        if await tg.is_user_authorized():
            print("AUTHORIZED")
            return
        sent = await tg.send_code_request(phone)
        hash_path = phone_code_hash_path()
        hash_path.write_text(sent.phone_code_hash, encoding="utf-8")
        os.chmod(hash_path, 0o600)
        print("CODE_SENT")
    finally:
        await tg.disconnect()


async def login(code: str) -> None:
    phone = str(config_value("mtproto", "phone"))
    password = str(
        config_value(
            "mtproto",
            "two_factor_password",
            "",
            allow_empty=True,
        )
    )
    tg = client()
    await tg.connect()
    try:
        if await tg.is_user_authorized():
            print("AUTHORIZED")
            return
        hash_path = phone_code_hash_path()
        if not hash_path.exists():
            raise RuntimeError("Run send-code before login/import")
        phone_code_hash = hash_path.read_text(encoding="utf-8").strip()
        try:
            await tg.sign_in(phone=phone, code=code, phone_code_hash=phone_code_hash)
        except SessionPasswordNeededError:
            if password == "":
                raise RuntimeError("Two-step password is required in config.php")
            await tg.sign_in(password=password)
        hash_path.unlink(missing_ok=True)
        print("AUTHORIZED")
    finally:
        await tg.disconnect()


def page_from_buttons(message: Message) -> tuple[int, int]:
    for row in message.buttons or []:
        for button in row:
            text = (button.text or "").strip()
            match = re.fullmatch(r"(\d+)\s*/\s*(\d+)", text)
            if match:
                return int(match.group(1)), int(match.group(2))
    return 1, 1


def text_url_entities(message: Message) -> list[str]:
    urls: list[str] = []
    for entity in message.entities or []:
        if isinstance(entity, MessageEntityTextUrl) and entity.url:
            if "://t.me/addemoji/" in entity.url or entity.url.startswith("https://t.me/addemoji/"):
                urls.append(entity.url)
    return urls


def set_name_from_url(url: str) -> str:
    return urlsplit(url).path.rstrip("/").split("/")[-1]


def parse_catalog_rows(message: Message) -> list[dict[str, object]]:
    urls = text_url_entities(message)
    lines = [
        raw_line.strip()
        for raw_line in (message.raw_text or "").splitlines()
        if "Открыть [" in raw_line
    ]
    if not lines:
        raise RuntimeError("Catalog page contains no emoji sets")
    if len(lines) != len(urls):
        raise RuntimeError(
            f"Catalog page is incomplete: rows={len(lines)} links={len(urls)}"
        )

    rows: list[dict[str, object]] = []
    for line, url in zip(lines, urls, strict=True):
        match = re.match(r"^(.*?)\s+Открыть\s+\[(\d+)\]\s*$", line)
        if not match:
            raise RuntimeError(f"Invalid catalog row: {line[:120]}")
        preview = match.group(1).strip()
        rows.append(
            {
                "set_name": set_name_from_url(url),
                "preview": preview,
                "url": url,
                "sticker_count": int(match.group(2)),
            }
        )
    return rows


async def latest_catalog_message(tg: TelegramClient, bot: object) -> Message:
    async for message in tg.iter_messages(bot, limit=10):
        if message.raw_text and "КАТАЛОГ ЭМОДЗИ" in message.raw_text and message.buttons:
            return message
    raise RuntimeError("Catalog message was not found")


async def click_button(message: Message, text_part: str) -> Message:
    if not message.buttons:
        raise RuntimeError(f"Message has no buttons for {text_part}")
    for row in message.buttons:
        for button in row:
            if text_part in (button.text or ""):
                result = await message.click(text=button.text)
                await asyncio.sleep(0.8)
                if isinstance(result, Message):
                    return result
                return await message.client.get_messages(message.peer_id, ids=message.id)
    raise RuntimeError(f"Button not found: {text_part}")


def validate_catalog(category: str, rows: Sequence[dict[str, object]]) -> None:
    if not re.fullmatch(r"[a-z0-9_-]{1,64}", category):
        raise RuntimeError("Invalid catalog category")
    if not rows:
        raise RuntimeError("Refusing to replace the catalog with an empty result")

    seen: set[str] = set()
    for position, row in enumerate(rows, start=1):
        set_name = str(row.get("set_name", ""))
        preview = str(row.get("preview", "")).strip()
        url = str(row.get("url", ""))
        sticker_count = row.get("sticker_count")
        parsed = urlsplit(url)

        if not re.fullmatch(r"[A-Za-z0-9_]{1,190}", set_name):
            raise RuntimeError(f"Invalid emoji set name at position {position}")
        if set_name in seen:
            raise RuntimeError(f"Duplicate emoji set in catalog: {set_name}")
        seen.add(set_name)
        if preview == "":
            raise RuntimeError(f"Empty emoji preview for set {set_name}")
        if (
            parsed.scheme != "https"
            or parsed.netloc.lower() != "t.me"
            or parsed.path != f"/addemoji/{set_name}"
            or parsed.query
            or parsed.fragment
            or len(url) > 255
        ):
            raise RuntimeError(f"Invalid emoji set URL for {set_name}")
        if (
            not isinstance(sticker_count, int)
            or isinstance(sticker_count, bool)
            or sticker_count <= 0
            or sticker_count > 1_000_000
        ):
            raise RuntimeError(f"Invalid sticker count for {set_name}")


def replace_catalog(category: str, rows: Sequence[dict[str, object]]) -> int:
    sql = """
        INSERT INTO posted_emoji_catalog
            (category, set_name, preview, url, sticker_count, sort_order, is_active)
        VALUES
            (%s, %s, %s, %s, %s, %s, 1)
        ON DUPLICATE KEY UPDATE
            category = VALUES(category),
            preview = VALUES(preview),
            url = VALUES(url),
            sticker_count = VALUES(sticker_count),
            sort_order = VALUES(sort_order),
            is_active = 1,
            updated_at = NOW()
    """
    db = db_connection()
    try:
        db.begin()
        with db.cursor() as cursor:
            cursor.execute(
                "UPDATE posted_emoji_catalog SET is_active = 0 WHERE category = %s",
                (category,),
            )
            for sort_order, row in enumerate(rows, start=1):
                cursor.execute(
                    sql,
                    (
                        category,
                        row["set_name"],
                        row["preview"],
                        row["url"],
                        row["sticker_count"],
                        sort_order,
                    ),
                )
        db.commit()
    except BaseException:
        db.rollback()
        raise
    finally:
        db.close()
    return len(rows)


async def import_category(category: str, button_text: str) -> None:
    if not re.fullmatch(r"[a-z0-9_-]{1,64}", category):
        raise RuntimeError("Invalid catalog category")
    if button_text.strip() == "":
        raise RuntimeError("Catalog button text is required")

    code = os.environ.get("POSTED_TG_CODE", "")
    authorized = False

    tg = client()
    await tg.connect()
    try:
        authorized = await tg.is_user_authorized()
    finally:
        await tg.disconnect()
    if not authorized:
        if code == "":
            raise RuntimeError("Not authorized. Run send-code, then import with POSTED_TG_CODE")
        await login(code)

    tg = client()
    await tg.connect()
    try:
        bot = await tg.get_entity(str(config_value("mtproto", "source_bot")))
        await tg.send_message(bot, "Каталог эмодзи")
        await asyncio.sleep(1.2)
        message = await latest_catalog_message(tg, bot)
        message = await click_button(message, button_text)

        catalog_rows: list[dict[str, object]] = []
        expected_page = 1
        expected_total_pages: int | None = None
        while True:
            current_page, total_pages = page_from_buttons(message)
            if total_pages < 1 or total_pages > 1_000:
                raise RuntimeError(f"Invalid catalog page count: {total_pages}")
            if current_page != expected_page:
                raise RuntimeError(
                    f"Unexpected catalog page: got {current_page}, expected {expected_page}"
                )
            if expected_total_pages is None:
                expected_total_pages = total_pages
            elif total_pages != expected_total_pages:
                raise RuntimeError(
                    "Catalog changed while it was being imported; retry the import"
                )

            rows = parse_catalog_rows(message)
            catalog_rows.extend(rows)
            if len(catalog_rows) > 100_000:
                raise RuntimeError("Catalog is unexpectedly large")
            print(f"PAGE {current_page}/{total_pages}: parsed={len(rows)}")

            if current_page >= total_pages:
                break
            message = await click_button(message, ">")
            expected_page += 1

        validate_catalog(category, catalog_rows)
        total_saved = replace_catalog(category, catalog_rows)
        print(f"DONE category={category} saved={total_saved}")
    finally:
        await tg.disconnect()


def main() -> int:
    parser = argparse.ArgumentParser()
    sub = parser.add_subparsers(dest="command", required=True)
    sub.add_parser("send-code")
    login_parser = sub.add_parser("login")
    login_parser.add_argument("--code", default=os.environ.get("POSTED_TG_CODE", ""))
    import_parser = sub.add_parser("import")
    import_parser.add_argument("--category", default="fonts")
    import_parser.add_argument("--button", default="Шрифты")
    args = parser.parse_args()

    if args.command == "send-code":
        asyncio.run(send_code())
    elif args.command == "login":
        if args.code == "":
            raise RuntimeError("--code or POSTED_TG_CODE is required")
        asyncio.run(login(args.code))
    elif args.command == "import":
        asyncio.run(import_category(args.category, args.button))
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        raise SystemExit(1)
