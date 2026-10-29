#!/usr/bin/env python3
"""Build a deterministic public installer archive; never read production data."""
from __future__ import annotations

import argparse
import gzip
import hashlib
import io
import json
import os
from pathlib import Path, PurePosixPath
import re
import stat
import tarfile
import tempfile

PREFIX = "gitposted-installer"
ARCHIVE = PREFIX + ".tar.gz"
MANIFEST = "MANIFEST.sha256"
MAX_FILE_BYTES = 100 * 1024 * 1024
MAX_TOTAL_BYTES = 512 * 1024 * 1024
ROOT_FILES = {"bootstrap.sh", "README.md", "AGENTS.md", "LICENSE", "LICENSE.txt", "LICENSE.md", "VERSION", ".gitignore", ".gitattributes"}
SOURCE_EXTENSIONS = {
    "bin": {".php", ".py", ".sh"}, "lib": {".php", ".py"},
    "web": {".php", ".js", ".css", ".svg", ".png", ".webp", ".ico"},
    "scripts": {".py", ".sh"}, "tests": {".php", ".py", ".sh", ".js", ".cjs"}, "docs": {".md", ".txt"},
    "deploy": {".service", ".socket", ".timer", ".conf", ".ini"},
}
PUBLIC_EXTENSIONS = {".css", ".js", ".svg", ".png", ".jpg", ".jpeg", ".webp", ".ico", ".woff", ".woff2", ".ttf", ".txt"}
TOOLS = {"import_emoji_catalog_mtproto.py", "import_post_link_telegram.py", "forward_post_link_telegram.py", "read_discussion_source_telegram_v3.py", "post_link_requirements.txt", "verify_installation.php"}
RESOURCES = {"payload/resources/assets/schedule.jpg", "payload/resources/assets/preview-links.png", "payload/resources/certs/russiantrustedca.pem"}
FORBIDDEN_PARTS = {".git", ".env", ".venv", "venv", "__pycache__", ".pytest_cache", "node_modules", "secrets", "backups", "backup", "database", "data", "storage", "runtime", "sessions", "tmp", "logs"}
FORBIDDEN_SUFFIXES = {".pyc", ".pyo", ".session", ".db", ".sqlite", ".sqlite3", ".sql", ".gz", ".zip", ".bak", ".old", ".orig", ".log", ".key", ".p12", ".pfx", ".dump"}


class PackageError(ValueError):
    pass


def safe_relative(name: str) -> PurePosixPath:
    path = PurePosixPath(name)
    if not name or "\\" in name or any(ord(char) < 32 or ord(char) == 127 for char in name) or path.is_absolute() \
            or any(part in {"", ".", ".."} for part in name.split("/")) or str(path) != name:
        raise PackageError("Noncanonical package path")
    return path


def allowed_file(name: str) -> bool:
    path = safe_relative(name)
    parts = path.parts
    lower_parts = [part.lower() for part in parts]
    if any(part in FORBIDDEN_PARTS for part in lower_parts) or path.suffix.lower() in FORBIDDEN_SUFFIXES:
        return False
    if path.name.lower() in {"config.php", "config.local.php", "config.production.php", "known_hosts", "authorized_keys", "id_rsa", "id_ed25519"} \
            or any(part.startswith(".env") for part in lower_parts):
        return False
    if any(part.startswith(".") for part in parts) and name not in {".gitignore", ".gitattributes", "payload/src/.htaccess"}:
        return False
    if len(parts) == 1:
        return name in ROOT_FILES
    if parts[0] in SOURCE_EXTENSIONS:
        return path.suffix.lower() in SOURCE_EXTENSIONS[parts[0]]
    if name in RESOURCES:
        return True
    if parts[:2] != ("payload", "src"):
        return False
    tail = parts[2:]
    if len(tail) == 1:
        return tail[0] in {".htaccess", "web-mode.html", "web-mode.js", "web-mode.css"} or path.suffix == ".php"
    if tail[0] == "tools":
        return len(tail) == 2 and tail[1] in TOOLS
    if tail[0] == "assets":
        return path.suffix.lower() in PUBLIC_EXTENSIONS
    if tail[0] == "control":
        return len(tail) == 2 and path.suffix.lower() in {".php", ".js", ".css"}
    if tail[0] in {"privacy", "user-agreement"}:
        return len(tail) == 2 and tail[1] == "index.php"
    return False


def reject_secret_bytes(name: str, value: bytes) -> None:
    # A CA certificate is public; an actual private key or renamed SQLite session is not.
    if re.search(rb"-----BEGIN (?:[A-Z ]+ )?PRIVATE KEY-----\r?\n", value) or value.startswith(b"SQLite format 3\0") \
            or (value.startswith(b"PuTTY-User-Key-File-") and b"Private-Lines:" in value):
        raise PackageError("Private key/session content is forbidden: " + name)
    if re.search(rb"(?<![A-Za-z0-9])\d{6,12}:[A-Za-z0-9_-]{30,200}(?![A-Za-z0-9_-])", value):
        raise PackageError("Literal Telegram token is forbidden: " + name)
    if re.search(rb"['\"](?:max_bot_token|api_hash|database_password|webhook_secret|max_webhook_secret)['\"]\s*(?:=>|:)\s*['\"][A-Za-z0-9_:/+.-]{24,}['\"]", value):
        raise PackageError("Literal credential value is forbidden: " + name)


def linked(path: Path) -> bool:
    info = path.lstat()
    return stat.S_ISLNK(info.st_mode) or bool(getattr(info, "st_file_attributes", 0) & 0x400)


def collect(root: Path, *, verify: bool = False) -> dict[str, bytes]:
    if linked(root) or not root.is_dir():
        raise PackageError("Package root must be an ordinary directory")
    result: dict[str, bytes] = {}
    seen: set[str] = set()
    total = 0
    for current, directories, filenames in os.walk(root, followlinks=False):
        current_path = Path(current)
        # A normal source checkout is supported without ever reading Git credentials.
        # Extracted release verification still rejects extra VCS metadata.
        if current_path == root and not verify and ".git" in directories:
            if linked(root / ".git"):
                raise PackageError("Linked Git metadata is forbidden")
            directories.remove(".git")
        for child in directories + filenames:
            path = current_path / child
            relative = path.relative_to(root).as_posix()
            safe_relative(relative)
            if linked(path):
                raise PackageError("Symlink/reparse point is forbidden: " + relative)
            if relative in {".git", "install.sh"} and not verify and path.is_file():
                continue
            if path.is_dir():
                if child.lower() in FORBIDDEN_PARTS:
                    raise PackageError("Private/cache directory is forbidden: " + relative)
                continue
            if not stat.S_ISREG(path.stat().st_mode):
                raise PackageError("Only regular files may be packaged: " + relative)
            if relative == MANIFEST and verify:
                continue
            if not allowed_file(relative):
                raise PackageError("File is outside the public allowlist: " + relative)
            if relative.casefold() in seen:
                raise PackageError("Case-colliding package path: " + relative)
            seen.add(relative.casefold())
            size = path.stat().st_size
            if size > MAX_FILE_BYTES:
                raise PackageError("Oversized package file: " + relative)
            value = path.read_bytes()
            if len(value) != size:
                raise PackageError("Package file changed while building: " + relative)
            total += size
            if total > MAX_TOTAL_BYTES:
                raise PackageError("Package is unexpectedly large")
            reject_secret_bytes(relative, value)
            result[relative] = value
    if not result:
        raise PackageError("Package is empty")
    return dict(sorted(result.items()))


def manifest_bytes(files: dict[str, bytes]) -> bytes:
    return "".join(hashlib.sha256(value).hexdigest() + "  " + name + "\n" for name, value in sorted(files.items())).encode("utf-8")


def verify_manifest(root: Path) -> int:
    root = root.absolute()
    files = collect(root, verify=True)
    manifest_path = root / MANIFEST
    if not manifest_path.is_file() or linked(manifest_path):
        raise PackageError("Manifest is missing or linked")
    try:
        lines = manifest_path.read_text(encoding="utf-8").splitlines()
    except (UnicodeError, OSError) as error:
        raise PackageError("Manifest is unreadable") from error
    expected: dict[str, str] = {}
    for line in lines:
        matched = re.fullmatch(r"([a-f0-9]{64})  (.+)", line)
        if matched is None:
            raise PackageError("Invalid manifest entry")
        digest, name = matched.groups()
        safe_relative(name)
        if not allowed_file(name) or name in expected:
            raise PackageError("Duplicate or forbidden manifest entry")
        expected[name] = digest
    if set(files) != set(expected):
        raise PackageError("Manifest file set differs from bundle contents")
    for name, value in files.items():
        if hashlib.sha256(value).hexdigest() != expected[name]:
            raise PackageError("Checksum failed: " + name)
    return len(files)


def build(source: Path, output: Path) -> dict[str, object]:
    source = source.absolute()
    if linked(source):
        raise PackageError("Linked source root is forbidden")
    source_real = source.resolve(strict=True)
    output_real = output.absolute().resolve()
    if output_real == source_real or source_real in output_real.parents:
        raise PackageError("Output must be outside the installer source tree")
    files = collect(source)
    files[MANIFEST] = manifest_bytes(files)
    output_real.mkdir(parents=True, exist_ok=True)
    if not output_real.is_dir():
        raise PackageError("Output is not a directory")
    archive_path = output_real / ARCHIVE
    checksum_path = output_real / (ARCHIVE + ".sha256")
    if any(path.is_symlink() or (path.exists() and not path.is_file()) for path in (archive_path, checksum_path)):
        raise PackageError("Output target must be an ordinary file")
    descriptor, temporary_value = tempfile.mkstemp(prefix=".gitposted-installer-", suffix=".tmp", dir=output_real)
    temporary = Path(temporary_value)
    try:
        with os.fdopen(descriptor, "wb") as raw:
            with gzip.GzipFile(fileobj=raw, mode="wb", filename="", mtime=0, compresslevel=9) as compressed:
                with tarfile.open(fileobj=compressed, mode="w", format=tarfile.PAX_FORMAT) as archive:
                    directories = {PREFIX}
                    for name in files:
                        directories.update(str(parent) for parent in (PurePosixPath(PREFIX) / name).parents if str(parent) != ".")
                    for name in sorted(directories):
                        entry = tarfile.TarInfo(name + "/")
                        entry.type = tarfile.DIRTYPE
                        entry.mode = 0o755
                        entry.mtime = 0
                        archive.addfile(entry)
                    for name, value in sorted(files.items()):
                        entry = tarfile.TarInfo(PREFIX + "/" + name)
                        entry.mode = 0o755 if name.endswith(".sh") or (name.startswith(("bin/", "scripts/")) and name.endswith(".py")) else 0o644
                        entry.size = len(value)
                        entry.mtime = 0
                        archive.addfile(entry, io.BytesIO(value))
            raw.flush()
            os.fsync(raw.fileno())
        digest = hashlib.sha256(temporary.read_bytes()).hexdigest()
        os.replace(temporary, archive_path)
        # Exact filename only; no recursive cleanup and no files written into source.
        check_descriptor, check_value = tempfile.mkstemp(prefix=".gitposted-checksum-", suffix=".tmp", dir=output_real)
        check_temporary = Path(check_value)
        try:
            with os.fdopen(check_descriptor, "wb") as check_output:
                check_output.write((digest + "  " + ARCHIVE + "\n").encode("ascii"))
                check_output.flush()
                os.fsync(check_output.fileno())
            os.replace(check_temporary, checksum_path)
        finally:
            check_temporary.unlink(missing_ok=True)
        return {"archive": str(archive_path), "sha256": digest, "file_count": len(files) - 1}
    finally:
        temporary.unlink(missing_ok=True)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    action = parser.add_mutually_exclusive_group(required=True)
    action.add_argument("--output", type=Path, help="Output directory outside INSTALLER")
    action.add_argument("--verify-manifest", type=Path, help="Verify an extracted bundle, including absence of extra files")
    arguments = parser.parse_args()
    try:
        result = build(Path(__file__).resolve().parents[1], arguments.output) if arguments.output else {"verified_files": verify_manifest(arguments.verify_manifest)}
        print(json.dumps({"ok": True, "result": result}, ensure_ascii=True))
        return 0
    except (OSError, PackageError) as error:
        print(json.dumps({"ok": False, "error": str(error)}, ensure_ascii=True))
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
