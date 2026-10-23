"""Remove only expired webhook minute counters; preserve all persistent state."""
from __future__ import annotations

import os
from pathlib import Path
import re
import stat
import time

ROOT = Path('/var/lib/gitposted/storage/rate_limits')
COUNTER_NAME = re.compile(r'[a-f0-9]{64}\.json\Z')


def cleanup(root: Path = ROOT, *, now: float | None = None) -> int:
    """No client paths are accepted by the CLI; root/clock are test adapters."""
    root = Path(root)
    if not root.is_absolute() or root.name != 'rate_limits' or root.parent.name != 'storage':
        return 0
    uid = os.getuid()
    ancestors = (root.parent.parent, root.parent, root)

    def safe_directories() -> bool:
        for directory in ancestors:
            try:
                info = directory.lstat()
                if not stat.S_ISDIR(info.st_mode) or info.st_uid != uid or directory.resolve() != directory:
                    return False
            except (OSError, RuntimeError):
                return False
        return True

    if not safe_directories():
        return 0
    deadline = (time.time() if now is None else now) - 300
    removed = 0
    for path in root.iterdir():
        if COUNTER_NAME.fullmatch(path.name) is None:
            continue
        try:
            info = path.lstat()
            if not stat.S_ISREG(info.st_mode) or info.st_uid != uid or info.st_mtime >= deadline:
                continue
            # The timer runs as the application UID. Recheck its writable
            # ancestors before unlinking, never follow an unexpected symlink.
            if not safe_directories():
                return removed
            path.unlink()
            removed += 1
        except FileNotFoundError:
            continue  # Another request/cleanup already removed this old counter.
    return removed


if __name__ == '__main__':
    cleanup()
