#!/usr/bin/env python3
"""Read a Telegram channel post using only GitPosted's BOT authorization.

One bounded JSON job arrives on stdin; stdout is one JSON result. Importing this
module never loads credentials, imports Telethon, authorizes, or accesses a DB.
The caller owns the empty private job directory and promotes successful assets.
"""
from __future__ import annotations

import asyncio
import hashlib
import io
import json
import os
import re
import stat
import sys
import time
from contextlib import contextmanager
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any

MAX_BYTES = 536_870_912
PHOTO_MAX_BYTES = 10 * 1024 * 1024
FILE_MAX_BYTES = 50 * 1024 * 1024
MAX_MESSAGE_ID = 2_147_483_647
MESSAGES_WINDOW = 10


class ImportFailure(Exception):
    def __init__(self, code: str, message: str):
        super().__init__(message)
        self.code = code


def reject(code: str, message: str) -> None:
    raise ImportFailure(code, message)


def positive(value: Any, maximum: int, label: str) -> int:
    if isinstance(value, bool) or not isinstance(value, int) or not 0 < value <= maximum:
        reject("INVALID_JOB", f"Некорректный параметр {label}.")
    return value


def private_directory(path: Path, strict: bool = True) -> None:
    if not path.is_dir() or path.is_symlink():
        reject("UNSAFE_PATH", "Закрытый каталог импорта недоступен.")
    info = path.stat()
    forbidden = 0o077 if strict else 0o002
    if os.name == "posix" and (info.st_uid != os.geteuid() or stat.S_IMODE(info.st_mode) & forbidden):
        reject("UNSAFE_PATH", "Каталог импорта должен принадлежать процессу и иметь режим 0700.")


@dataclass
class Job:
    api_id: int
    api_hash: str = field(repr=False)
    bot_token: str = field(repr=False)
    bot_id: int
    data_dir: Path
    job_dir: Path
    source: dict[str, Any]
    actor_id: int | None
    max_bytes: int = MAX_BYTES
    timeout: int = 90
    session_path: Path | None = None

    @classmethod
    def parse(cls, value: Any) -> "Job":
        if not isinstance(value, dict):
            reject("INVALID_JOB", "Ожидалось задание JSON.")
        api_id = positive(value.get("api_id"), MAX_MESSAGE_ID, "api_id")
        api_hash = value.get("api_hash")
        token = value.get("bot_token")
        if not isinstance(api_hash, str) or re.fullmatch(r"[a-fA-F0-9]{32}", api_hash) is None:
            reject("INVALID_JOB", "Не настроены параметры приложения Telegram.")
        match = re.fullmatch(r"([1-9][0-9]{5,11}):[A-Za-z0-9_-]{30,200}", token or "") if isinstance(token, str) else None
        if match is None:
            reject("INVALID_JOB", "Не настроен токен Telegram-бота.")
        bot_id = int(match[1])
        data_value, job_value = value.get("data_dir"), value.get("job_dir")
        if not isinstance(data_value, str) or not isinstance(job_value, str):
            reject("UNSAFE_PATH", "Не задан закрытый каталог импорта.")
        data = Path(data_value)
        target = Path(job_value)
        if not data.is_absolute() or not target.is_absolute() or not data.is_dir():
            reject("UNSAFE_PATH", "Некорректный путь импорта.")
        data = data.resolve()
        base = data / "tmp" / "post-links"
        # Exactly one private child; never accept a path outside the configured root.
        if target.parent != base or target.name in ("", ".", "..") or not re.fullmatch(r"[A-Za-z0-9_-]{1,100}", target.name):
            reject("UNSAFE_PATH", "Каталог задания находится вне разрешённого пути.")
        for directory in (data / "tmp", base, target):
            private_directory(directory, strict=directory != data / "tmp")
            if directory.resolve() != directory:
                reject("UNSAFE_PATH", "Ссылки в пути импорта запрещены.")
        if any(target.iterdir()):
            reject("UNSAFE_PATH", "Каталог нового задания должен быть пустым.")
        source = value.get("source")
        if not isinstance(source, dict):
            reject("INVALID_JOB", "Не задан исходный пост.")
        message_id = positive(source.get("message_id"), MAX_MESSAGE_ID, "message_id")
        username = source.get("username")
        channel_id = source.get("channel_id")
        if username is not None and (not isinstance(username, str) or re.fullmatch(r"[A-Za-z][A-Za-z0-9_]{0,31}", username) is None):
            reject("INVALID_JOB", "Некорректное имя канала.")
        if channel_id is not None:
            positive(channel_id, 999_999_999_999, "channel_id")
        if username is None and channel_id is None:
            reject("INVALID_JOB", "Не указан канал исходного поста.")
        url = source.get("url")
        if not isinstance(url, str) or len(url) > 2048 or re.search(r"[\x00-\x20\x7f]", url):
            reject("INVALID_JOB", "Некорректная ссылка исходного поста.")
        actor = value.get("actor_telegram_id")
        if actor is not None:
            positive(actor, 9_223_372_036_854_775_807, "actor_telegram_id")
        maximum = value.get("max_bytes", MAX_BYTES)
        positive(maximum, MAX_BYTES, "max_bytes")
        timeout = positive(value.get("timeout_seconds", 90), 300, "timeout_seconds")
        if timeout < 5:
            reject("INVALID_JOB", "Слишком короткое время импорта.")
        session_value = value.get("session_path")
        session = None
        if session_value is not None:
            if not isinstance(session_value, str):
                reject("UNSAFE_PATH", "Некорректный путь сессии бота.")
            session = Path(session_value)
            expected = data / "runtime" / "post-links-bot" / f"bot-{bot_id}.session"
            if session != expected:
                reject("UNSAFE_PATH", "Разрешена только отдельная сессия этого бота.")
            for directory in (data / "runtime", session.parent):
                private_directory(directory, strict=directory != data / "runtime")
                if directory.resolve() != directory:
                    reject("UNSAFE_PATH", "Ссылки в пути сессии запрещены.")
            if session.exists() or session.is_symlink():
                if session.is_symlink() or not session.is_file():
                    reject("UNSAFE_PATH", "Некорректный файл сессии бота.")
                info = session.stat()
                if os.name == "posix" and (info.st_uid != os.geteuid() or stat.S_IMODE(info.st_mode) != 0o600):
                    reject("UNSAFE_PATH", "Сессия бота должна иметь режим 0600.")
        return cls(api_id, api_hash, token, bot_id, data, target,
                   {"url": url, "username": username, "channel_id": channel_id, "message_id": message_id},
                   actor, maximum, timeout, session)


@contextmanager
def session_lock(job: Job):
    if job.session_path is None:
        yield
        return
    import fcntl  # production is Linux; offline pure tests do not need Telethon
    path = Path(str(job.session_path) + ".lock")
    if path.is_symlink():
        reject("UNSAFE_PATH", "Некорректная блокировка сессии бота.")
    descriptor = os.open(path, os.O_RDWR | os.O_CREAT | getattr(os, "O_NOFOLLOW", 0), 0o600)
    try:
        os.fchmod(descriptor, 0o600)
        deadline = time.monotonic() + 5
        while True:
            try:
                fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
                break
            except BlockingIOError:
                if time.monotonic() >= deadline:
                    reject("SESSION_BUSY", "Импорт уже выполняется. Повторите через несколько секунд.")
                time.sleep(0.05)
        yield
    finally:
        fcntl.flock(descriptor, fcntl.LOCK_UN)
        os.close(descriptor)


class BotClient:
    """Narrow Telethon port: no phone login, writes, joins, dialogs or history."""
    def __init__(self, job: Job):
        from telethon import TelegramClient
        from telethon.sessions import MemorySession
        self.client = TelegramClient(str(job.session_path) if job.session_path else MemorySession(),
            job.api_id, job.api_hash, connection_retries=1, request_retries=0,
            flood_sleep_threshold=0, timeout=15, receive_updates=False)

    async def authorize_bot(self, token: str):
        await self.client.connect()
        if not await self.client.is_user_authorized():
            await self.client.sign_in(bot_token=token)
        return await self.client.get_me()

    async def resolve_channel(self, username: str | None, channel_id: int | None):
        from telethon.tl.functions.channels import GetChannelsRequest
        from telethon.tl.functions.contacts import ResolveUsernameRequest
        from telethon.tl.types import PeerChannel
        from telethon.utils import get_input_channel
        if username is not None:
            result = await self.client(ResolveUsernameRequest(username))
            identity = getattr(result.peer, "channel_id", None)
            return next((chat for chat in result.chats if getattr(chat, "id", None) == identity), None)
        # Reuse this bot session's access hash for known private channels. An
        # explicit zero hash would bypass the SDK cache and reject valid access.
        # Telethon still tries a zero hash itself after a cache miss, so a bot's
        # first import from an already joined private channel keeps working.
        try:
            peer = await self.client.get_input_entity(PeerChannel(channel_id))
        except ValueError:
            reject("SOURCE_UNAVAILABLE", "Закрытый канал недоступен отдельной сессии бота.")
        result = await self.client(GetChannelsRequest([get_input_channel(peer)]))
        return next((chat for chat in result.chats if getattr(chat, "id", None) == channel_id), None)

    async def get_participant(self, channel, actor: int):
        from telethon.tl.functions.channels import GetParticipantRequest
        from telethon.tl.types import InputPeerUser
        result = await self.client(GetParticipantRequest(channel, InputPeerUser(actor, 0)))
        return result.participant

    async def get_messages(self, channel, ids: list[int]):
        return await self.client.get_messages(channel, ids=ids)

    async def download_media(self, message, file, progress_callback):
        return await self.client.download_media(message, file=file, progress_callback=progress_callback)

    async def disconnect(self):
        await self.client.disconnect()


def kind(value: Any) -> str:
    return type(value).__name__


def channel_peer(message) -> int | None:
    return getattr(getattr(message, "peer_id", None), "channel_id", None)


def active_usernames(channel) -> set[str]:
    names = set()
    if getattr(channel, "username", None):
        names.add(channel.username.lower())
    for item in getattr(channel, "usernames", None) or []:
        if getattr(item, "active", False) and isinstance(getattr(item, "username", None), str):
            names.add(item.username.lower())
    return names


async def assert_private_source_access(client, channel, actor_id: int | None) -> None:
    if actor_id is None:
        reject("PRIVATE_ACCESS_REQUIRED", "Для закрытого канала требуется подтверждение доступа пользователя.")
    participant = await client.get_participant(channel, actor_id)
    if kind(participant) not in {"ChannelParticipant", "ChannelParticipantSelf", "ChannelParticipantCreator", "ChannelParticipantAdmin"}:
        reject("PRIVATE_ACCESS_DENIED", "У пользователя нет доступа к закрытому каналу.")


def channel_forward_origin(message) -> dict[str, Any] | None:
    """Only an explicit channel forward can replace the source's own origin."""
    header = getattr(message, "fwd_from", None)
    peer = getattr(header, "from_id", None)
    identity, post = getattr(peer, "channel_id", None), getattr(header, "channel_post", None)
    if kind(peer) != "PeerChannel" or isinstance(identity, bool) or not isinstance(identity, int) or not 0 < identity <= 999_999_999_999:
        return None
    if isinstance(post, bool) or not isinstance(post, int) or not 0 < post <= MAX_MESSAGE_ID:
        return None
    date = getattr(header, "date", None)
    if hasattr(date, "timestamp"): date = int(date.timestamp())
    if isinstance(date, bool) or not isinstance(date, int) or date < 1:
        return None
    return {"type": "channel", "date": date, "chat": {"id": str(-(1_000_000_000_000 + identity)), "type": "channel"}, "message_id": post}


async def explicit_messages(client, channel, ids: list[int]) -> list[Any]:
    result = await client.get_messages(channel, ids)
    if not isinstance(result, (list, tuple)) or len(result) != len(ids):
        reject("BAD_RESPONSE", "Telegram вернул неполный ответ на запрос постов.")
    for requested, message in zip(ids, result):
        if message is None or kind(message) == "MessageEmpty":
            continue
        if getattr(message, "id", None) != requested or channel_peer(message) != channel.id:
            reject("SOURCE_MISMATCH", "Ответ Telegram не соответствует исходной ссылке.")
    return list(result)


def entities_for(message, text: str) -> list[dict[str, Any]]:
    mapping = {
        "MessageEntityMention": "mention", "MessageEntityHashtag": "hashtag",
        "MessageEntityBotCommand": "bot_command", "MessageEntityUrl": "url", "MessageEntityEmail": "email",
        "MessageEntityBold": "bold", "MessageEntityItalic": "italic", "MessageEntityCode": "code",
        "MessageEntityPre": "pre", "MessageEntityTextUrl": "text_link", "MessageEntityMentionName": "text_mention",
        "MessageEntityPhone": "phone_number", "MessageEntityCashtag": "cashtag", "MessageEntityUnderline": "underline",
        "MessageEntityStrike": "strikethrough", "MessageEntityBlockquote": "blockquote",
        "MessageEntitySpoiler": "spoiler", "MessageEntityCustomEmoji": "custom_emoji",
    }
    encoded = text.encode("utf-16-le")
    result = []
    for entity in getattr(message, "entities", None) or []:
        name = kind(entity)
        if name not in mapping or len(result) >= 1000:
            reject("UNSUPPORTED_ENTITY", "Оформление исходного поста пока не поддерживается без потери данных.")
        offset, length = getattr(entity, "offset", -1), getattr(entity, "length", 0)
        if isinstance(offset, bool) or isinstance(length, bool) or not isinstance(offset, int) or not isinstance(length, int) or offset < 0 or length < 1 or (offset + length) * 2 > len(encoded):
            reject("BAD_RESPONSE", "В исходном посте некорректное оформление.")
        try:
            encoded[:offset * 2].decode("utf-16-le")
            encoded[offset * 2:(offset + length) * 2].decode("utf-16-le")
        except UnicodeDecodeError:
            reject("BAD_RESPONSE", "Оформление разрывает символ исходного поста.")
        value = {"type": mapping[name], "offset": offset, "length": length}
        if name == "MessageEntityPre": value["language"] = str(getattr(entity, "language", ""))
        elif name == "MessageEntityTextUrl": value["url"] = str(getattr(entity, "url", ""))
        elif name == "MessageEntityCustomEmoji": value["custom_emoji_id"] = str(getattr(entity, "document_id", ""))
        elif name == "MessageEntityMentionName":
            value["user"] = {"id": positive(getattr(entity, "user_id", None), 9_223_372_036_854_775_807, "mention user"), "first_name": "Пользователь"}
        elif name == "MessageEntityBlockquote" and getattr(entity, "collapsed", False): value["type"] = "expandable_blockquote"
        result.append(value)
    return result


class Budget:
    def __init__(self, maximum: int): self.maximum, self.used = maximum, 0


class LimitedWriter(io.IOBase):
    def __init__(self, handle, budget: Budget, file_limit: int):
        self.handle, self.budget, self.file_limit, self.written = handle, budget, file_limit, 0
    def write(self, data):
        if self.written + len(data) > self.file_limit:
            reject("TOO_LARGE", "Файл превышает лимит отправки Telegram: фото 10 МиБ, остальные медиа 50 МиБ.")
        if self.budget.used + len(data) > self.budget.maximum:
            reject("TOO_LARGE", "Медиа исходного поста превышают общий лимит импорта.")
        count = self.handle.write(data)
        self.budget.used += count
        self.written += count
        return count
    def flush(self):
        if not self.handle.closed: self.handle.flush()
    def writable(self): return True
    def tell(self): return self.handle.tell()


def media_description(message) -> tuple[str | None, dict[str, Any], str, int]:
    media = getattr(message, "media", None)
    if media is None or kind(media) == "MessageMediaWebPage": return None, {}, "", 0
    if getattr(media, "ttl_seconds", None):
        reject("UNSUPPORTED_MEDIA", "Самоуничтожающееся медиа нельзя импортировать.")
    if kind(media) == "MessageMediaPhoto" and getattr(media, "photo", None) is not None:
        sizes = [item for item in getattr(media.photo, "sizes", []) if getattr(item, "w", 0) and getattr(item, "h", 0)]
        if not sizes: reject("UNSUPPORTED_MEDIA", "Фото исходного поста недоступно.")
        size = max(sizes, key=lambda item: item.w * item.h)
        declared = max([getattr(size, "size", 0), *(getattr(size, "sizes", []) or []), len(getattr(size, "bytes", b""))])
        return "photo", {"width": size.w, "height": size.h, "mime_type": "image/jpeg"}, "jpg", declared
    if kind(media) != "MessageMediaDocument" or getattr(media, "document", None) is None:
        reject("UNSUPPORTED_MEDIA", "Этот тип медиа нельзя импортировать без потери содержимого.")
    document = media.document
    mime = getattr(document, "mime_type", "application/octet-stream") or "application/octet-stream"
    if not isinstance(mime, str) or re.fullmatch(r"[A-Za-z0-9.+_-]+/[A-Za-z0-9.+_-]+", mime) is None:
        reject("BAD_RESPONSE", "Некорректный тип файла исходного поста.")
    description, media_type, animation = {"mime_type": mime}, "document", False
    for attribute in getattr(document, "attributes", []) or []:
        name = kind(attribute)
        if name in ("DocumentAttributeSticker", "DocumentAttributeCustomEmoji"):
            reject("UNSUPPORTED_MEDIA", "Стикер нельзя импортировать как обычное медиа.")
        if name == "DocumentAttributeFilename":
            raw_name = str(getattr(attribute, "file_name", "file")).replace("\\", "/").split("/")[-1]
            description["file_name"] = re.sub(r'[\x00-\x1f\x7f"]', "_", raw_name)[:150].lstrip(".") or "file"
        elif name == "DocumentAttributeVideo":
            if getattr(attribute, "round_message", False):
                reject("UNSUPPORTED_MEDIA", "Видеосообщение нельзя импортировать как обычное видео.")
            media_type = "video"
            description.update(width=getattr(attribute, "w", 0), height=getattr(attribute, "h", 0), duration=getattr(attribute, "duration", 0))
        elif name == "DocumentAttributeAudio":
            media_type = "voice" if getattr(attribute, "voice", False) else "audio"
            description.update(duration=getattr(attribute, "duration", 0))
            for key in ("title", "performer"):
                if getattr(attribute, key, None): description[key] = str(getattr(attribute, key))[:1000]
        elif name == "DocumentAttributeAnimated": animation = True
    if animation: media_type = "animation"
    extension = {"image/jpeg": "jpg", "image/png": "png", "image/webp": "webp", "video/mp4": "mp4", "video/quicktime": "mov",
        "audio/ogg": "ogg", "audio/mpeg": "mp3", "audio/mp4": "m4a", "application/pdf": "pdf", "image/gif": "gif"}.get(mime.lower(), "bin")
    declared = getattr(document, "size", 0)
    if isinstance(declared, bool) or not isinstance(declared, int) or declared < 0:
        reject("BAD_RESPONSE", "Некорректный размер исходного файла.")
    return media_type, description, extension, declared


def buttons_for(message, warnings: set[str]) -> dict[str, Any] | None:
    markup = getattr(message, "reply_markup", None)
    if markup is None: return None
    if kind(markup) != "ReplyInlineMarkup":
        warnings.add("NON_URL_BUTTONS_OMITTED")
        return None
    rows = []
    for row in getattr(markup, "rows", []) or []:
        buttons = []
        for button in getattr(row, "buttons", []) or []:
            if kind(button) == "KeyboardButtonUrl" and isinstance(getattr(button, "url", None), str):
                # Retain ordinary links; never execute source callbacks/login/payment buttons.
                if re.match(r"\Ahttps?://", button.url, re.I) and not re.search(r"[\x00-\x20\x7f]", button.url):
                    buttons.append({"text": str(getattr(button, "text", ""))[:1000], "url": button.url})
                else: warnings.add("NON_HTTP_BUTTONS_OMITTED")
            else: warnings.add("NON_URL_BUTTONS_OMITTED")
        if buttons: rows.append(buttons)
    return {"inline_keyboard": rows} if rows else None


async def import_post(job: Job, client) -> dict[str, Any]:
    created: list[Path] = []
    try:
        me = await client.authorize_bot(job.bot_token)
        if not getattr(me, "bot", False) or getattr(me, "id", None) != job.bot_id:
            reject("BOT_IDENTITY_MISMATCH", "Сессия не принадлежит настроенному Telegram-боту.")
        channel = await client.resolve_channel(job.source["username"], job.source["channel_id"])
        if channel is None or not getattr(channel, "broadcast", False) or getattr(channel, "megagroup", False):
            reject("SOURCE_UNAVAILABLE", "Ссылка должна указывать на доступный пост канала.")
        if job.source["channel_id"] is not None and getattr(channel, "id", None) != job.source["channel_id"]:
            reject("SOURCE_MISMATCH", "Канал не соответствует исходной ссылке.")
        names = active_usernames(channel)
        username = job.source["username"]
        if username is not None and username.lower() not in names:
            reject("SOURCE_MISMATCH", "Публичное имя канала не подтверждено Telegram.")
        public, verified = bool(names), False
        if not public:
            await assert_private_source_access(client, channel, job.actor_id)
            verified = True
        if getattr(channel, "noforwards", False): reject("PROTECTED_CONTENT", "В исходном канале запрещено копирование содержимого.")
        requested = job.source["message_id"]
        target = (await explicit_messages(client, channel, [requested]))[0]
        if target is None or kind(target) == "MessageEmpty": reject("POST_NOT_FOUND", "Исходный пост удалён или недоступен.")
        grouped = getattr(target, "grouped_id", None)
        messages = [target]
        if grouped is not None:
            lower, upper = max(1, requested - MESSAGES_WINDOW), min(MAX_MESSAGE_ID, requested + MESSAGES_WINDOW)
            window = await explicit_messages(client, channel, list(range(lower, upper + 1)))
            messages = [item for item in window if item is not None and kind(item) != "MessageEmpty" and getattr(item, "grouped_id", None) == grouped]
            messages.sort(key=lambda item: item.id)
            ids = [item.id for item in messages]
            if requested not in ids or not 2 <= len(ids) <= 10 or ids != list(range(ids[0], ids[-1] + 1)):
                reject("ALBUM_INCOMPLETE", "Не удалось получить альбом целиком. Перешлите полный альбом боту.")
            if (ids[0] == lower and lower != 1) or (ids[-1] == upper and upper != MAX_MESSAGE_ID):
                reject("ALBUM_INCOMPLETE", "Границы альбома не подтверждены. Перешлите полный альбом боту.")
        # A rejected member must not leave an earlier downloaded partial album.
        for message in messages:
            if getattr(message, "noforwards", False): reject("PROTECTED_CONTENT", "В исходном посте запрещено копирование содержимого.")
            media_type, _, _, declared = media_description(message)
            limit = PHOTO_MAX_BYTES if media_type == "photo" else FILE_MAX_BYTES
            if declared > limit:
                reject("TOO_LARGE", "Файл превышает лимит отправки Telegram: фото 10 МиБ, остальные медиа 50 МиБ.")
        bot_chat = str(-(1_000_000_000_000 + positive(channel.id, 999_999_999_999, "channel id")))
        normalized, files, warnings, budget = [], [], set(), Budget(job.max_bytes)
        for message in messages:
            text = getattr(message, "message", "") or ""
            if not isinstance(text, str) or len(text.encode("utf-8")) > 65536:
                reject("BAD_RESPONSE", "Исходный текст слишком большой или повреждён.")
            media_type, description, extension, declared = media_description(message)
            value = {"message_id": message.id, "chat": {"id": bot_chat, "type": "channel"}, "has_protected_content": False}
            origin = channel_forward_origin(message)
            if origin is not None: value["forward_origin"] = origin
            if public: value["chat"]["username"] = username or sorted(names)[0]
            if grouped is not None: value["media_group_id"] = str(grouped)
            entities = entities_for(message, text)
            if media_type is None:
                if not text: reject("UNSUPPORTED_MESSAGE", "Пустой или служебный пост нельзя импортировать.")
                value.update(text=text, entities=entities)
            else:
                if declared > budget.maximum - budget.used: reject("TOO_LARGE", "Медиа исходного поста превышают общий лимит импорта.")
                path = job.job_dir / f"media-{message.id}.{extension}"
                descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, "O_NOFOLLOW", 0), 0o600)
                created.append(path)
                with os.fdopen(descriptor, "wb") as output:
                    if hasattr(os, "fchmod"): os.fchmod(output.fileno(), 0o600)
                    else: os.chmod(path, 0o600)
                    start = budget.used
                    file_limit = PHOTO_MAX_BYTES if media_type == "photo" else FILE_MAX_BYTES
                    def progress(current, total):
                        if total > file_limit or current > file_limit:
                            reject("TOO_LARGE", "Файл превышает лимит отправки Telegram: фото 10 МиБ, остальные медиа 50 МиБ.")
                        if total > budget.maximum - start or current > budget.maximum - start:
                            reject("TOO_LARGE", "Медиа исходного поста превышают общий лимит импорта.")
                    await client.download_media(message, LimitedWriter(output, budget, file_limit), progress)
                    output.flush()
                    os.fsync(output.fileno())
                size = path.stat().st_size
                if size < 1 or (declared > 0 and size != declared): reject("DOWNLOAD_INCOMPLETE", "Не удалось загрузить исходный файл целиком.")
                digest = hashlib.sha256()
                with path.open("rb") as downloaded:
                    while chunk := downloaded.read(1_048_576): digest.update(chunk)
                description.update(local_path=str(path), file_size=size)
                if getattr(getattr(message, "media", None), "spoiler", False): value["has_media_spoiler"] = True
                value.update(caption=text, caption_entities=entities)
                value[media_type] = [description] if media_type == "photo" else description
                files.append({"path": str(path), "sha256": digest.hexdigest(), "size": size, "mime_type": description["mime_type"], "extension": extension, "message_id": message.id})
            keyboard = buttons_for(message, warnings)
            if keyboard: value["reply_markup"] = keyboard
            normalized.append(value)
        if not public:
            # Downloads may take most of the worker deadline. A revoked actor
            # must not receive the completed private snapshot after that wait.
            await assert_private_source_access(client, channel, job.actor_id)
        return {"ok": True, "platform": "telegram", "url": job.source["url"], "source_chat_id": bot_chat,
            "source_message_ids": [item.id for item in messages], "messages": normalized, "complete": True,
            "access": {"public": public, "actor_verified": verified, "actor_id": job.actor_id}, "files": files, "warnings": sorted(warnings)}
    except BaseException:
        for path in created:
            # Only exact files opened by this job; no recursive deletion or user paths.
            try: path.unlink(missing_ok=True)
            except OSError: pass
        raise
    finally:
        try:
            await asyncio.wait_for(client.disconnect(), timeout=3)
        except Exception:
            pass  # disconnect cannot invalidate a completed snapshot or mask the original failure
        if job.session_path and job.session_path.is_file(): os.chmod(job.session_path, 0o600)


def safe_error(exception: BaseException) -> dict[str, Any]:
    if isinstance(exception, ImportFailure): return {"ok": False, "error": {"code": exception.code, "message": str(exception)}}
    name = type(exception).__name__
    if isinstance(exception, (asyncio.TimeoutError, TimeoutError)):
        code, message = "TIMEOUT", "Telegram не ответил вовремя. Повторите импорт позже."
    elif "FloodWait" in name:
        code, message = "RATE_LIMITED", "Telegram временно ограничил запросы. Повторите импорт позже."
    elif name in {"UserNotParticipantError", "ChatAdminRequiredError"}:
        code, message = "PRIVATE_ACCESS_DENIED", "Не удалось подтвердить доступ пользователя к закрытому каналу."
    elif name in {"ChannelPrivateError", "ChannelInvalidError", "UsernameNotOccupiedError", "UsernameInvalidError"}:
        code, message = "SOURCE_UNAVAILABLE", "Исходный канал или пост недоступен боту."
    elif any(word in name for word in ("Auth", "AccessToken", "ApiId")):
        code, message = "AUTH_FAILED", "Авторизация Telegram-бота временно недоступна."
    else: code, message = "IMPORT_FAILED", "Не удалось импортировать пост. Исходные публикации не изменены."
    return {"ok": False, "error": {"code": code, "message": message}}


def main() -> int:
    os.umask(0o077)
    try:
        raw = sys.stdin.buffer.read(65537)
        if len(raw) > 65536: reject("INVALID_JOB", "Задание импорта слишком большое.")
        try: value = json.loads(raw)
        except (ValueError, UnicodeDecodeError): reject("INVALID_JOB", "Некорректное задание JSON.")
        job = Job.parse(value)
        with session_lock(job):
            async def execute():
                return await asyncio.wait_for(import_post(job, BotClient(job)), timeout=job.timeout)
            result = asyncio.run(execute())
    except BaseException as exception:
        result = safe_error(exception)
    sys.stdout.buffer.write((json.dumps(result, ensure_ascii=False, separators=(",", ":")) + "\n").encode("utf-8"))
    return 0 if result.get("ok") else 1


if __name__ == "__main__":
    raise SystemExit(main())
