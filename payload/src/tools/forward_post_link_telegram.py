#!/usr/bin/env python3
"""One authorized bot forward. Secrets arrive on stdin; no personal login exists."""
from __future__ import annotations

import asyncio
import importlib.util
import json
import os
from pathlib import Path
import re
import sys
import time
from dataclasses import dataclass
from typing import Any

sys.dont_write_bytecode = True

_spec = importlib.util.spec_from_file_location("gitposted_post_link_import", Path(__file__).with_name("import_post_link_telegram.py"))
loader = importlib.util.module_from_spec(_spec)
sys.modules[_spec.name] = loader
_spec.loader.exec_module(loader)


def signed_id(value: Any, channel_only: bool = False) -> int:
    if not isinstance(value, str) or re.fullmatch(r"-?[1-9][0-9]{0,18}", value) is None:
        loader.reject("INVALID_JOB", "Некорректный ID чата репоста.")
    identity = int(value)
    if str(identity) != value or not -1_999_999_999_999 <= identity <= 9_223_372_036_854_775_807:
        loader.reject("INVALID_JOB", "Некорректный ID чата репоста.")
    if identity < 0 and not 1_000_000_000_000 < -identity <= 1_999_999_999_999:
        loader.reject("INVALID_JOB", "Разрешены только каналы Telegram.")
    if channel_only and identity > 0:
        loader.reject("INVALID_JOB", "Источник должен быть каналом.")
    return identity


@dataclass
class ForwardJob:
    job: Any
    source_chat: int
    target_chat: int
    target_username: str | None
    random_id: int
    silent: bool
    dry_run: bool
    protect_content: bool = False

    @classmethod
    def parse(cls, value: Any):
        job = loader.Job.parse(value)
        source = signed_id(value.get("expected_source_chat_id"), True)
        target = signed_id(value.get("target_chat_id"))
        username = value.get("target_username")
        if username is not None and (not isinstance(username, str) or re.fullmatch(r"[A-Za-z][A-Za-z0-9_]{0,31}", username) is None):
            loader.reject("INVALID_JOB", "Некорректное публичное имя целевого канала.")
        if target > 0 and (username is not None or target != job.actor_id):
            loader.reject("TARGET_ACCESS_DENIED", "Предпросмотр разрешён только автору репоста.")
        random_id = loader.positive(value.get("random_id"), 9_223_372_036_854_775_807, "random_id")
        for name in ("silent", "dry_run"):
            if not isinstance(value.get(name), bool): loader.reject("INVALID_JOB", "Некорректные настройки репоста.")
        protect_content = value.get("protect_content", False)
        if not isinstance(protect_content, bool): loader.reject("INVALID_JOB", "Некорректная защита пересылки.")
        return cls(job, source, target, username, random_id, value["silent"], value["dry_run"], protect_content)


class ForwardClient(loader.BotClient):
    async def own_participant(self, channel):
        from telethon.tl.functions.channels import GetParticipantRequest
        from telethon.tl.types import InputPeerSelf
        result = await self.client(GetParticipantRequest(channel, InputPeerSelf()))
        return result.participant

    async def resolve_actor(self, identity: int):
        from telethon.tl.types import InputPeerUser, PeerUser
        try:
            peer = await self.client.get_input_entity(PeerUser(identity))
        except ValueError:
            peer = InputPeerUser(identity, 0)
        if loader.kind(peer) != "InputPeerUser" or getattr(peer, "user_id", None) != identity:
            loader.reject("TARGET_MISMATCH", "Получатель предпросмотра не подтверждён Telegram.")
        return peer

    def validate_forward(self, source, target, message_id: int, random_id: int, silent: bool, protect_content: bool = False):
        from telethon.tl.functions.messages import ForwardMessagesRequest
        from telethon.utils import get_input_peer
        return ForwardMessagesRequest(
            from_peer=get_input_peer(source), id=[message_id], random_id=[random_id], to_peer=get_input_peer(target),
            silent=silent, drop_author=False, noforwards=protect_content,
        )

    async def forward(self, source, target, message_id: int, random_id: int, silent: bool, progress, protect_content: bool = False):
        request = self.validate_forward(source, target, message_id, random_id, silent, protect_content)
        progress["send_started"] = True
        return await self.client(request)


def peer_id(peer) -> int | None:
    if loader.kind(peer) == "PeerChannel":
        value = getattr(peer, "channel_id", None)
        if isinstance(value, int) and not isinstance(value, bool) and 0 < value <= 999_999_999_999:
            return -(1_000_000_000_000 + value)
    if loader.kind(peer) == "PeerUser":
        value = getattr(peer, "user_id", None)
        if isinstance(value, int) and not isinstance(value, bool) and 0 < value <= 9_223_372_036_854_775_807:
            return value
    return None


def unix_date(value) -> int:
    if hasattr(value, "timestamp"): value = int(value.timestamp())
    if isinstance(value, bool) or not isinstance(value, int) or value < 1:
        loader.reject("BAD_RESPONSE", "Telegram не вернул дату отправленного репоста.")
    return value


def forward_origin(message) -> dict[str, Any] | None:
    channel = loader.channel_forward_origin(message)
    if channel is not None: return channel
    header = getattr(message, "fwd_from", None)
    if header is None: return None
    date = unix_date(getattr(header, "date", None))
    sender = getattr(header, "from_id", None)
    if loader.kind(sender) == "PeerUser":
        identity = loader.positive(getattr(sender, "user_id", None), 9_223_372_036_854_775_807, "forward user")
        return {"type": "user", "date": date, "sender_user": {"id": identity, "is_bot": False, "first_name": "Пользователь"}}
    name = getattr(header, "from_name", None)
    if isinstance(name, str) and name and len(name.encode("utf-8")) <= 4096:
        return {"type": "hidden_user", "date": date, "sender_user_name": name}
    return None


def origin_key(origin: dict[str, Any]) -> tuple:
    kind = origin.get("type")
    if kind == "channel": return (kind, str(origin["chat"]["id"]), origin["message_id"])
    if kind == "user": return (kind, origin["sender_user"]["id"])
    if kind == "hidden_user": return (kind, origin["sender_user_name"])
    return ("unsupported",)


def accepted_message(response, plan: ForwardJob, expected_origin: dict[str, Any]) -> dict[str, Any]:
    updates = getattr(response, "updates", None)
    if not isinstance(updates, (list, tuple)):
        loader.reject("ACCEPTED_MESSAGE_MISSING", "Telegram не вернул подтверждение репоста.")
    correlations = [item for item in updates if loader.kind(item) == "UpdateMessageID"]
    ids = {item.id for item in correlations if getattr(item, "random_id", None) == plan.random_id
           and isinstance(getattr(item, "id", None), int) and not isinstance(item.id, bool)
           and 0 < item.id <= loader.MAX_MESSAGE_ID}
    # All random_id methods generating a Message emit updateMessageID. A
    # matching forward header alone can belong to another concurrent sender.
    if len(ids) != 1:
        loader.reject("ACCEPTED_MESSAGE_MISSING", "Telegram не связал результат с запросом репоста.")
    candidates = {}
    for update in updates:
        if loader.kind(update) not in {"UpdateNewMessage", "UpdateNewChannelMessage"}: continue
        message = getattr(update, "message", None)
        identity = getattr(message, "id", None)
        if loader.kind(message) != "Message" or peer_id(getattr(message, "peer_id", None)) != plan.target_chat: continue
        if identity not in ids: continue
        if isinstance(identity, bool) or not isinstance(identity, int) or not 0 < identity <= loader.MAX_MESSAGE_ID: continue
        origin = forward_origin(message)
        if origin is None or origin_key(origin) != origin_key(expected_origin): continue
        candidates[identity] = (message, origin)
    if len(candidates) != 1:
        loader.reject("ACCEPTED_MESSAGE_MISSING", "Telegram не вернул однозначное подтверждение репоста.")
    identity, (message, origin) = next(iter(candidates.items()))
    result = {"message_id": identity, "chat": {"id": str(plan.target_chat), "type": "channel" if plan.target_chat < 0 else "private"},
              "date": unix_date(getattr(message, "date", None)), "forward_origin": origin}
    text = getattr(message, "message", "") or ""
    if not isinstance(text, str) or len(text.encode("utf-8")) > 65536:
        loader.reject("BAD_RESPONSE", "Telegram не вернул корректное содержимое репоста.")
    result["caption" if getattr(message, "media", None) is not None else "text"] = text
    return result


def error_result(exception: BaseException, send_started: bool) -> dict[str, Any]:
    name = type(exception).__name__
    rpc_code = getattr(exception, "code", None)
    rpc_code = rpc_code if isinstance(rpc_code, int) and not isinstance(rpc_code, bool) else 0
    if "FloodWait" in name or "SlowModeWait" in name or rpc_code == 420 or rpc_code == 429:
        seconds = getattr(exception, "seconds", 60)
        seconds = min(86400, max(1, seconds)) if isinstance(seconds, int) and not isinstance(seconds, bool) else 60
        return {"ok": False, "delivery_may_be_unknown": False,
                "error": {"code": "RATE_LIMITED", "message": "Telegram временно ограничил пересылку. Повторите позже.", "http_status": 429, "retry_after": seconds}}
    known_rpc = 400 <= rpc_code < 500 and rpc_code not in {408, 420, 429}
    unknown = bool(send_started and not known_rpc)
    if isinstance(exception, loader.ImportFailure):
        code, message = exception.code, str(exception)
    elif known_rpc:
        code, message = "FORWARD_REJECTED", "Telegram отклонил репост. Проверьте исходный пост и права бота."
    elif isinstance(exception, (asyncio.TimeoutError, TimeoutError)):
        code, message = "TIMEOUT", "Telegram не ответил вовремя."
    else:
        code, message = "FORWARD_FAILED", "Не удалось завершить пересылку Telegram."
    return {"ok": False, "delivery_may_be_unknown": unknown,
            "error": {"code": code, "message": message, "http_status": rpc_code if 400 <= rpc_code <= 599 else (400 if isinstance(exception, loader.ImportFailure) else 0)}}


async def resolve_source(plan: ForwardJob, client):
    username = plan.job.source["username"]
    if username is None:
        return await client.resolve_channel(None, plan.job.source["channel_id"])
    try:
        source = await client.resolve_channel(username, None)
    except BaseException as error:
        if type(error).__name__ not in {"UsernameNotOccupiedError", "UsernameInvalidError", "ChannelPrivateError", "ChannelInvalidError", "ValueError"}:
            raise
        source = None
    expected = -plan.source_chat - 1_000_000_000_000
    if source is not None and getattr(source, "id", None) == expected and username.lower() in loader.active_usernames(source):
        return source
    # A scheduled post belongs to its immutable channel, even after the public
    # username was renamed or reassigned. Current access is checked below.
    return await client.resolve_channel(None, expected)


async def forward_post(plan: ForwardJob, client, progress: dict[str, bool] | None = None) -> dict[str, Any]:
    progress = progress if progress is not None else {"send_started": False}
    try:
        job = plan.job
        me = await client.authorize_bot(job.bot_token)
        if not getattr(me, "bot", False) or getattr(me, "id", None) != job.bot_id:
            loader.reject("BOT_IDENTITY_MISMATCH", "Сессия не принадлежит настроенному Telegram-боту.")
        source = await resolve_source(plan, client)
        if source is None or not getattr(source, "broadcast", False) or getattr(source, "megagroup", False):
            loader.reject("SOURCE_UNAVAILABLE", "Исходный канал недоступен боту.")
        if -(1_000_000_000_000 + loader.positive(getattr(source, "id", None), 999_999_999_999, "source channel")) != plan.source_chat:
            loader.reject("SOURCE_MISMATCH", "Исходный канал изменился. Репост отменён.")
        if job.source["channel_id"] is not None and source.id != job.source["channel_id"]:
            loader.reject("SOURCE_MISMATCH", "Исходный канал не совпадает со ссылкой.")
        names = loader.active_usernames(source)
        if not names:
            await loader.assert_private_source_access(client, source, job.actor_id)
        message = (await loader.explicit_messages(client, source, [job.source["message_id"]]))[0]
        if message is None or loader.kind(message) != "Message": loader.reject("POST_NOT_FOUND", "Исходный пост удалён или недоступен.")
        if getattr(source, "noforwards", False) or getattr(message, "noforwards", False):
            loader.reject("PROTECTED_CONTENT", "В исходном канале запрещена пересылка.")
        if getattr(message, "grouped_id", None) is not None:
            loader.reject("ALBUM_REQUIRES_COPY", "Для целого альбома выберите полную копию.")
        expected = forward_origin(message)
        if expected is None:
            expected = {"type": "channel", "chat": {"id": str(plan.source_chat)}, "message_id": job.source["message_id"]}
        if plan.target_chat < 0:
            identity = -plan.target_chat - 1_000_000_000_000
            target = await client.resolve_channel(plan.target_username, None if plan.target_username is not None else identity)
            if target is None or getattr(target, "id", None) != identity or not getattr(target, "broadcast", False) or getattr(target, "megagroup", False):
                loader.reject("TARGET_MISMATCH", "Целевой канал не совпадает с выбранным.")
            if plan.target_username is not None and plan.target_username.lower() not in loader.active_usernames(target):
                loader.reject("TARGET_MISMATCH", "Публичное имя целевого канала изменилось.")
            participant = await client.own_participant(target)
            if loader.kind(participant) != "ChannelParticipantCreator" and not (
                loader.kind(participant) == "ChannelParticipantAdmin" and getattr(getattr(participant, "admin_rights", None), "post_messages", False) is True
            ):
                loader.reject("TARGET_ACCESS_DENIED", "У бота нет права публикации в целевом канале.")
        else:
            target = await client.resolve_actor(plan.target_chat)
        client.validate_forward(source, target, job.source["message_id"], plan.random_id, plan.silent, plan.protect_content)
        if not names:
            # Target resolution/permission RPCs can be slow; recheck the actor
            # immediately before delivery rather than reuse the earlier grant.
            await loader.assert_private_source_access(client, source, job.actor_id)
        if plan.dry_run:
            return {"ok": True, "dry_run": True, "source_chat_id": str(plan.source_chat), "source_message_id": job.source["message_id"], "target_chat_id": str(plan.target_chat)}
        response = await client.forward(source, target, job.source["message_id"], plan.random_id, plan.silent, progress, plan.protect_content)
        result = accepted_message(response, plan, expected)
        return {"ok": True, "result": result}
    except BaseException as exception:
        return error_result(exception, progress["send_started"])
    finally:
        try: await asyncio.wait_for(client.disconnect(), timeout=3)
        except BaseException: pass


def main() -> int:
    os.umask(0o077)
    started = time.monotonic()
    progress = {"send_started": False}
    try:
        raw = sys.stdin.buffer.read(65537)
        if len(raw) > 65536: loader.reject("INVALID_JOB", "Слишком большое задание репоста.")
        try: value = json.loads(raw)
        except (ValueError, UnicodeDecodeError): loader.reject("INVALID_JOB", "Некорректное задание репоста.")
        plan = ForwardJob.parse(value)
        with loader.session_lock(plan.job):
            async def execute():
                budget = max(0.1, min(plan.job.timeout, 85) - (time.monotonic() - started) - 3)
                return await asyncio.wait_for(forward_post(plan, ForwardClient(plan.job), progress), timeout=budget)
            result = asyncio.run(execute())
    except BaseException as exception:
        result = error_result(exception, progress["send_started"])
    sys.stdout.buffer.write((json.dumps(result, ensure_ascii=False, separators=(",", ":")) + "\n").encode("utf-8"))
    return 0 if result.get("ok") else 1


if __name__ == "__main__":
    raise SystemExit(main())
