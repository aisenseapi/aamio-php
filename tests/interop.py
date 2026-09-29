"""PHP and Python as one client: what one seals the other opens, what one signs the other verifies.

Run from aamio-php with the aamio-python checkout beside it:

    python tests/interop.py

Nothing touches the network. Python is the reference here because its box is
PyNaCl's, which the shared vectors were made with; PHP is the port under test.

And one count between them: the live tests of both keep the opens and closes
they make at the service in one file, so four runs, two of each, take turns on
it here and no window may hold more than the limit. A Python run that is slow
to write keeps its turn from a PHP run that asks meanwhile, however old its
lock looks. And a home one of them holds, the other stays out of.
"""

import json
import os
import shutil
import subprocess
import sys
import tempfile
import threading
import time

HERE = os.path.dirname(os.path.abspath(__file__))
PYTHON_TESTS = os.path.join(HERE, "..", "..", "aamio-python", "tests")
sys.path.insert(0, os.path.join(HERE, "..", "..", "aamio-python", "src"))

from aamio.crypto import Keys, thread_signing_input  # noqa: E402

PHP = os.environ.get("AAMIO_PHP") or os.path.join(os.environ.get("LOCALAPPDATA", ""), "aisense-php", "php.exe")
EXT = os.path.join(os.path.dirname(PHP), "ext")

PHP_SIDE = r'''<?php
require $argv[1] . "/bootstrap.php";
use Aamio\Keys;
$in = json_decode(stream_get_contents(STDIN), true);
$php = Keys::fromSeedHex($in["php_seed"]);
$out = ["php_public" => $php->public];
// open what Python sealed to us, and verify what Python signed
$out["opened"] = $php->open($in["py_public"], $in["envelope_from_py"]);
$out["py_signature_verifies"] = Keys::verify($in["py_public"], $in["py_signature"], $in["signing_input"]);
// seal to Python, and sign the same input
$out["envelope_from_php"] = $php->seal($in["py_public"], $in["plaintext_for_py"]);
$out["php_signature"] = $php->sign($in["signing_input"]);
echo json_encode($out);
'''

PACE_PHP = r'''<?php
require $argv[1] . "/bootstrap.php";
require $argv[1] . "/live-pace.inc.php";
$pace = new LivePace((int) $argv[3], (float) $argv[4], $argv[2]);
$stamps = [];
for ($n = 0; $n < (int) $argv[5]; $n++) {
    $pace->take();
    $stamps[] = $pace->last;
    usleep(random_int(0, 10000));
}
echo json_encode($stamps);
'''

PACE_PYTHON = r'''
import json, random, sys, time
sys.path.insert(0, sys.argv[1])
import live_pace
pace = live_pace.Pace(limit=int(sys.argv[3]), window=float(sys.argv[4]), path=sys.argv[2])
stamps = []
for _ in range(int(sys.argv[5])):
    pace.take()
    stamps.append(pace.last)
    time.sleep(random.uniform(0.0, 0.01))
print(json.dumps(stamps))
'''


def one_count():
    """Two PHP and two Python runs take turns on one file. The stamps are the ones written, so the rule is checked exactly."""
    if not os.path.isfile(os.path.join(PYTHON_TESTS, "live_pace.py")):
        return None, "no aamio-python/tests/live_pace.py beside this checkout"

    folder = tempfile.mkdtemp(prefix="aamio-interop-pace-")
    ledger = os.path.join(folder, "ledger.json")
    script = os.path.join(folder, "pace.php")
    limit, window, turns = 4, 0.5, 8

    with open(script, "w", encoding="utf-8") as handle:
        handle.write(PACE_PHP)

    try:
        runs = [[PHP, script, HERE, ledger, str(limit), str(window), str(turns)] for _ in range(2)]
        runs += [[sys.executable, "-c", PACE_PYTHON, PYTHON_TESTS, ledger, str(limit), str(window), str(turns)] for _ in range(2)]
        children = [subprocess.Popen(run, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True) for run in runs]
        stamps = []
        trouble = []

        for child in children:
            out, err = child.communicate(timeout=120)

            if child.returncode != 0 or err.strip():
                trouble.append("exit %s: %s" % (child.returncode, (err.strip() or out.strip())[:200]))
            else:
                stamps.extend(json.loads(out))

        lock_left = os.path.exists(ledger + ".lock")
    finally:
        shutil.rmtree(folder, ignore_errors=True)

    if trouble:
        return False, "; ".join(trouble)

    stamps.sort()
    most = max(sum(1 for later in stamps[index:] if later - first < window) for index, first in enumerate(stamps))
    ok = len(stamps) == 4 * turns and most <= limit and not lock_left

    return ok, "%d turns, at most %d inside one window of %.1f s where the limit is %d, lock left behind: %s" % (len(stamps), most, window, limit, lock_left)


ONE_TURN_PHP = r'''<?php
require $argv[1] . "/bootstrap.php";
require $argv[1] . "/live-pace.inc.php";
$pace = new LivePace((int) $argv[3], LivePace::WINDOW, $argv[2], null, null, (float) $argv[4]);
$error = null;
try {
    $pace->take();
} catch (RuntimeException $stopped) {
    $error = $stopped->getMessage();
}
echo json_encode(['taken' => $pace->taken, 'last' => $pace->last, 'error' => $error]);
'''

OWNER_PHP = r'''<?php
require $argv[1] . "/bootstrap.php";
try {
    $runtime = new Aamio\Runtime($argv[2], 'https://fake.test', ['interop'], false, static function (string $line): void {
    });
} catch (RuntimeException $refused) {
    echo 'refused: ' . str_replace("\n", ' ', $refused->getMessage()) . "\n";
    exit(0);
}
echo "owned\n";
fflush(STDOUT);
if ($argv[3] === 'hold') {
    fgets(STDIN);
}
$runtime->close();
'''


def slow_writer_keeps_its_turn():
    """The review of 29 September: a run that is only slow to write, with the turn in hand, and a run of the other client asking meanwhile."""
    if not os.path.isfile(os.path.join(PYTHON_TESTS, "live_pace.py")):
        return None, "no aamio-python/tests/live_pace.py beside this checkout"

    if PYTHON_TESTS not in sys.path:
        sys.path.insert(0, PYTHON_TESTS)

    import live_pace

    folder = tempfile.mkdtemp(prefix="aamio-interop-slow-")
    ledger = os.path.join(folder, "ledger.json")
    script = os.path.join(folder, "turn.php")
    entered, resume = threading.Event(), threading.Event()

    with open(script, "w", encoding="utf-8") as handle:
        handle.write(ONE_TURN_PHP)

    class SlowWriter(live_pace.Pace):
        def _write(self, stamps):
            entered.set()
            resume.wait(10)
            super()._write(stamps)

    first = SlowWriter(path=ledger, limit=2)
    worker = threading.Thread(target=first.take)
    worker.start()

    try:
        entered.wait(5)
        # However old the lock looks: the first version took one over at ten seconds.
        long_ago = time.time() - 7200
        os.utime(ledger + live_pace.TURN, (long_ago, long_ago))
        asked = subprocess.run([PHP, script, HERE, ledger, "2", "0.5"], capture_output=True, text=True, timeout=60)
    finally:
        resume.set()
        worker.join(10)

    try:
        meanwhile = json.loads(asked.stdout)
        after = json.loads(subprocess.run([PHP, script, HERE, ledger, "2", "5"], capture_output=True, text=True, timeout=60).stdout)

        with open(ledger, encoding="utf-8") as handle:
            stored = json.load(handle)
    finally:
        shutil.rmtree(folder, ignore_errors=True)

    ok = (meanwhile["taken"] == 0 and "out of reach" in (meanwhile["error"] or "") and first.taken == 1 and after["taken"] == 1
          and sorted(stored) == sorted([first.last, after["last"]]))

    return ok, json.dumps({"meanwhile": meanwhile, "after": after, "stored": stored, "first": first.last})


def one_owner_across_clients():
    """A home held by a runtime of one client keeps a runtime of the other out, both ways."""
    from aamio.runtime import Runtime

    folder = tempfile.mkdtemp(prefix="aamio-interop-owner-")
    home = os.path.join(folder, "home")
    script = os.path.join(folder, "owner.php")
    php = [PHP, "-d", "extension_dir=" + EXT, "-d", "extension=sodium", script, HERE, home]

    with open(script, "w", encoding="utf-8") as handle:
        handle.write(OWNER_PHP)

    try:
        held = Runtime(home=home, host="https://fake.test", archive=False)

        try:
            php_beside_python = subprocess.run(php + ["try"], capture_output=True, text=True, timeout=60).stdout.strip()
        finally:
            held.close()

        child = subprocess.Popen(php + ["hold"], stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)

        try:
            php_alone = child.stdout.readline().strip()

            try:
                Runtime(home=home, host="https://fake.test", archive=False).close()
                python_beside_php = "owned"
            except RuntimeError as refused:
                python_beside_php = "refused: " + str(refused)
        finally:
            child.communicate("done\n", timeout=30)
    finally:
        shutil.rmtree(folder, ignore_errors=True)

    ok = (php_beside_python.startswith("refused: another aamio (pid %d)" % os.getpid()) and php_alone == "owned"
          and python_beside_php.startswith("refused: another aamio (pid %d)" % child.pid))

    return ok, json.dumps([php_beside_python[:120], php_alone, python_beside_php[:120]])


def main():
    py = Keys(os.urandom(32))
    php_seed = os.urandom(32)
    signing_input = thread_signing_input("ohcibx4t22xc6hx22fch", '{"hello":"from python"}')
    request = {
        "php_seed": php_seed.hex(),
        "py_public": py.public,
        "signing_input": signing_input,
        "py_signature": py.sign(signing_input),
        "plaintext_for_py": "fra php, åpnet i python 🐘",
    }
    # Python seals to PHP: it needs PHP's public key first, derived from the seed here so the two derivations can be compared.
    from nacl.signing import SigningKey
    from aamio.crypto import b64url
    php_pub_b64 = b64url(bytes(SigningKey(php_seed).verify_key))
    request["envelope_from_py"] = py.seal(php_pub_b64, "fra python, åpnet i php 🐍".encode("utf-8"))

    # In the temp folder, not beside this file: a checkout in a synced folder
    # had the script held open by the sync client at the moment it was
    # removed, and the round stopped on that rather than on anything it checks.
    folder = tempfile.mkdtemp(prefix="aamio-interop-")
    script = os.path.join(folder, "side.php")
    with open(script, "w", encoding="utf-8") as handle:
        handle.write(PHP_SIDE)
    try:
        run = subprocess.run([PHP, "-d", "extension_dir=" + EXT, "-d", "extension=sodium", script, HERE], input=json.dumps(request).encode("utf-8"), capture_output=True, check=True)
    finally:
        shutil.rmtree(folder, ignore_errors=True)
    out = json.loads(run.stdout.decode("utf-8"))

    checks = []
    checks.append((out["php_public"] == php_pub_b64, "PHP derives the same public key from the seed as PyNaCl"))
    checks.append((out["opened"] == "fra python, åpnet i php 🐍", "PHP opens what Python sealed to it"))
    checks.append((out["py_signature_verifies"] is True, "PHP verifies Python's signature"))
    opened = py.open(php_pub_b64, out["envelope_from_php"]).decode("utf-8")
    checks.append((opened == request["plaintext_for_py"], "Python opens what PHP sealed to it"))
    from nacl.signing import VerifyKey
    from aamio.crypto import unb64url
    try:
        VerifyKey(unb64url(php_pub_b64)).verify(signing_input.encode("utf-8"), unb64url(out["php_signature"]))
        verified = True
    except Exception:
        verified = False
    checks.append((verified, "Python verifies PHP's signature"))

    counted, detail = one_count()
    label = "PHP and Python keep one count of the opens and closes their live tests make, and four runs on it never pass the limit"

    if counted is None:
        print("  skip  " + label + ": " + detail)
    else:
        checks.append((counted, label + ("" if counted else "  [" + detail + "]")))

    kept, detail = slow_writer_keeps_its_turn()
    label = "a Python run that is slow to write keeps its turn from a PHP run that asks meanwhile, however old the lock looks"

    if kept is None:
        print("  skip  " + label + ": " + detail)
    else:
        checks.append((kept, label + ("" if kept else "  [" + detail + "]")))

    owned, detail = one_owner_across_clients()
    checks.append((owned, "a home a runtime of one client holds keeps a runtime of the other out, both ways" + ("" if owned else "  [" + detail + "]")))

    failed = 0
    for ok, label in checks:
        print(("  ok    " if ok else "  FAIL  ") + label)
        failed += 0 if ok else 1
    print("\n%d passed, %d failed" % (len(checks) - failed, failed))
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
