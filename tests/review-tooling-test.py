"""Offline packaging and pre-push checks: uv run python tests/review-tooling-test.py.

All Git commits are disposable fixtures outside the checkout. No push or network is used.
Requires Git, Bash, PHP, zip and unzip, as does the release builder.
"""
from pathlib import Path
import os
import re
import shutil
import subprocess
import tempfile
import zipfile

ROOT = Path(__file__).resolve().parent.parent
BASH = shutil.which("bash")
assert BASH, "Bash is required"
if os.name == "nt":
    # Native Windows sort/find have different arguments; use Bash's own utilities.
    bash_bin = Path(BASH).parent
    os.environ["PATH"] = str(bash_bin) + os.pathsep + os.environ["PATH"]


def run(args, cwd, *, env=None, input=None, success=True):
    result = subprocess.run(args, cwd=cwd, env=env,
                            input=input.encode() if input is not None else None, capture_output=True)
    result.stdout = result.stdout.decode("utf-8", errors="replace")
    result.stderr = result.stderr.decode("utf-8", errors="replace")
    if success and result.returncode:
        raise AssertionError(f"{args}: {result.stdout}\n{result.stderr}")
    return result


def git(folder, *args):
    return run(["git", *args], folder).stdout.strip()


def init(folder):
    folder.mkdir()
    git(folder, "init", "--initial-branch=main")
    git(folder, "config", "core.autocrlf", "false")
    git(folder, "config", "core.hooksPath", str(folder / "no-hooks"))
    git(folder, "config", "commit.gpgsign", "false")
    git(folder, "config", "user.name", "Fixture")
    git(folder, "config", "user.email", "fixture@example.test")


def commit(folder):
    git(folder, "add", ".")
    git(folder, "commit", "--quiet", "-m", "Fixture")
    return git(folder, "rev-parse", "HEAD")


with tempfile.TemporaryDirectory(prefix="lichtbild-tooling-") as scratch:
    base = Path(scratch)
    repo = base / "hook"
    init(repo)
    home = base / "home"
    policy = home / "agent-memory/bin/audit-agent-memory.ps1"
    policy.parent.mkdir(parents=True)
    policy.write_text("$ForbiddenPatterns = @(\n    'PRIVATE_FIXTURE_MARKER'\n)\n$AllowedFiles = @(\n    'allowed.txt'\n)\n", encoding="utf-8")
    env = dict(os.environ, HOME=str(home), USERPROFILE=str(home), SKIP_AGENT_MEMORY_AUDIT="0")
    (repo / "safe.txt").write_text("safe\n", encoding="utf-8")
    clean = commit(repo)
    zeros = "0" * 40

    def hook(oid, remote, expected, label):
        result = run([BASH, str(ROOT / ".githooks/pre-push")], repo, env=env,
                     input=f"refs/heads/test {oid} refs/heads/test {remote}\n", success=False)
        assert (result.returncode == 0) == expected, (label, result.stdout, result.stderr)
        assert "PRIVATE_FIXTURE_MARKER" not in result.stdout + result.stderr, "hook leaked a value"
        print("[OK] " + label, flush=True)

    hook(clean, zeros, True, "new clean ref passes")
    (repo / "secret.txt").write_text("PRIVATE_FIXTURE_MARKER\n", encoding="utf-8")
    forbidden = commit(repo)
    (repo / "secret.txt").write_text("safe now\n", encoding="utf-8")
    hook(forbidden, clean, False, "worktree cleanup cannot hide an outgoing secret")
    repaired = commit(repo)
    hook(repaired, clean, False, "a later repair cannot hide an earlier outgoing commit")
    git(repo, "switch", "--detach", clean)
    hook(forbidden, clean, False, "pushing another ref scans that ref")
    hook(zeros, forbidden, True, "ref deletion publishes no objects")
    hook(clean, "f" * 40, False, "unavailable remote object fails closed")
    (repo / "allowed.txt").write_text("PRIVATE_FIXTURE_MARKER\n", encoding="utf-8")
    allowed = commit(repo)
    hook(allowed, clean, True, "shared policy file exception is honored")

    package = base / "package"
    init(package)
    paths = git(ROOT, "ls-files", "-z").split("\0")
    for name in filter(None, paths):
        source = ROOT / name
        if source.is_file():
            target = package / name
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(source.read_bytes())
    commit(package)

    def build(expected, label):
        result = run([BASH, "tools/build-zip.sh"], package, success=False)
        assert (result.returncode == 0) == expected, (label, result.stdout, result.stderr)
        print("[OK] " + label, flush=True)
        return result

    build(True, "current candidate builds from its committed fixture")
    archive, = (package / "build").glob("*.zip")
    with zipfile.ZipFile(archive) as bundle:
        names = bundle.namelist()
        assert not any("/.githooks/" in name for name in names), names
        packaged = bundle.read("lichtbild-gallery/lichtbild-gallery.php")
    print("[OK] archive excludes development hooks", flush=True)
    bootstrap = package / "lichtbild-gallery.php"
    original = bootstrap.read_bytes()
    bootstrap.write_bytes(original + b"\n// assets/js/missing-worktree.js\n")
    (package / ".distignore").write_text("includes\n", encoding="utf-8")
    build(True, "worktree references and exclusions cannot change HEAD validation")
    with zipfile.ZipFile(archive) as bundle:
        assert bundle.read("lichtbild-gallery/lichtbild-gallery.php") == packaged
    bootstrap.write_bytes(original + b"\n// assets/js/missing-committed.js\n")
    (package / ".distignore").write_bytes((ROOT / ".distignore").read_bytes())
    commit(package)
    bootstrap.write_bytes(original)
    result = build(False, "a working-tree repair cannot conceal a missing committed asset")
    assert "enqueued but absent: assets/js/missing-committed.js" in result.stdout
    inconsistent, count = re.subn(rb"(define\( 'LICHTBILD_VERSION', ')[^']+", rb"\g<1>0.0.1", original)
    assert count == 1
    bootstrap.write_bytes(inconsistent)
    commit(package)
    bootstrap.write_bytes(original)
    result = build(False, "a working-tree repair cannot conceal inconsistent committed versions")
    assert "version disagreement" in result.stdout
    print("[OK] all packaging and outgoing-history checks passed", flush=True)
