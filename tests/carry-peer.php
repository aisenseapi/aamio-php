<?php

declare(strict_types=1);

/*
 * One party of a carrier exchange over the simulated radio, for the tests across
 * runtimes. The same peer as tests/carry_peer.py in aamio-python, with the same
 * JSON file as its one argument, the same tasks (exchange, resave, deliver and
 * pending) and the same lines out, so any two runtimes can be paired.
 */

require __DIR__ . '/bootstrap.php';

use Aamio\Carrier;
use Aamio\CarryRefused;
use Aamio\CarryState;
use Aamio\Keys;
use Aamio\TransportProcess;

$config = json_decode((string) file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$keys = Keys::fromSeedHex($config['seed']);
$partner = hex2bin($config['partner']);
$say = static function (array $value): void {
    echo json_encode($value), "\n";
};

$task = $config['task'] ?? 'exchange';
if ($task === 'resave') {
    // Read a state another runtime wrote and write it back: prune at the time floor deletes nothing.
    (new CarryState($keys, $config['state']))->prune($config['now']);
    $say(['resaved' => true]);
    exit(0);
}
if ($task === 'deliver') {
    $state = new CarryState($keys, $config['state']);
    try {
        [$result, $reply] = $state->deliver(hex2bin($config['envelope']), [$partner], CarryState::profile($config['mode']), 0.0, $config['now']);
    } catch (CarryRefused $refused) {
        [$result, $reply] = [$refused->carryCode, null];
    }
    $say(['result' => $result, 'receipt' => $reply !== null, 'challenges' => $state->durable['challenges']]);
    exit(0);
}
if ($task === 'pending') {
    $say(['pending' => array_column((new CarryState($keys, $config['state']))->pending(), 4)]);
    exit(0);
}

$base = [];
foreach (['PATH', 'SYSTEMROOT', 'TEMP', 'TMP'] as $name) {
    if (getenv($name) !== false) {
        $base[$name] = (string) getenv($name);
    }
}
$env = TransportProcess::environment([], $base) + ['PYTHONPATH' => $config['sim_path'], 'AAMIO_SIM_PORT' => (string) $config['port'], 'AAMIO_SIM_PEERS' => (string) $config['peer']];
foreach ($config['medium'] as $key => $value) {
    $env[$key] = (string) $value;
}
$folder = dirname($config['state']);
@mkdir($folder, 0700, true);
$radio = new TransportProcess('sim-radio', '0.1.0', array_merge($config['python'], ['-u', '-m', 'aamio_sim_radio.transport']), $folder, $env);
$radio->start('0.0.0-test', 'php', $folder);
$carrier = new Carrier(new CarryState($keys, $config['state']), $radio, CarryState::profile($config['mode']), [$partner], null, null, 1.0, 3.0);
$until = microtime(true) + $config['seconds'];
$stage = 'start';
$sent = null;
$envelope = null;
$done = false;
$exit = 0;
try {
    while (!file_exists($config['stop'])) {
        if (microtime(true) > $until) {
            $say(['done' => false, 'stage' => $stage, 'refused' => $carrier->refused]);
            $exit = 1;
            break;
        }
        $carrier->pump(0.01);
        $opened = $carrier->openSession($partner);
        if ($config['role'] === 'first') {
            if ($stage === 'start') {
                $carrier->open($partner);
                $stage = 'opening';
            } elseif ($stage === 'opening' && $opened !== null) {
                $sent = $carrier->send($partner, $opened, $config['text']);
                $envelope = $carrier->messages[$sent]['envelope'];
                $stage = 'sending';
            } elseif ($stage === 'sending' && $carrier->confirmed($sent) && $carrier->inbox() !== []) {
                $stage = 'settling';
            }
        } else {
            if ($stage === 'start' && $carrier->inbox() !== []) {
                $carrier->open($partner);
                $stage = 'opening';
            } elseif ($stage === 'opening' && $opened !== null) {
                $sent = $carrier->send($partner, $opened, $config['text']);
                $envelope = $carrier->messages[$sent]['envelope'];
                $stage = 'sending';
            } elseif ($stage === 'sending' && $carrier->confirmed($sent)) {
                $stage = 'settling';
            }
        }
        if ($stage === 'settling' && !$done) {
            $done = true;
            $say(['done' => true]);
        }
    }
    if ($exit === 0) {
        $fromPartner = true;
        foreach ($carrier->inbox() as $entry) {
            $fromPartner = $fromPartner && $entry[0] === $partner;
        }
        $say(['stopped' => true, 'received' => array_column($carrier->inbox(), 3), 'from_partner' => $fromPartner,
            'sent' => $envelope === null ? null : bin2hex($envelope), 'refused' => (object) array_count_values($carrier->refused)]);
    }
} finally {
    $radio->stop();
}
exit($exit);
