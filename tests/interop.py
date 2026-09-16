"""PHP and Python as one client: what one seals the other opens, what one signs the other verifies.

Run from aamio-php with the aamio-python checkout beside it:

    python tests/interop.py

Nothing touches the network. Python is the reference here because its box is
PyNaCl's, which the shared vectors were made with; PHP is the port under test.
"""

import json
import os
import subprocess
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
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

    script = os.path.join(HERE, "_interop_side.php")
    with open(script, "w", encoding="utf-8") as handle:
        handle.write(PHP_SIDE)
    try:
        run = subprocess.run([PHP, "-d", "extension_dir=" + EXT, "-d", "extension=sodium", script, HERE], input=json.dumps(request).encode("utf-8"), capture_output=True, check=True)
    finally:
        os.remove(script)
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

    failed = 0
    for ok, label in checks:
        print(("  ok    " if ok else "  FAIL  ") + label)
        failed += 0 if ok else 1
    print("\n%d passed, %d failed" % (len(checks) - failed, failed))
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
