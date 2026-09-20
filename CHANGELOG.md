# Changelog

Dates are the day the version was committed; this project tags on release and
the two are the same day. Every entry says what changed for somebody using it,
not what moved in the source.

## 0.2.15 - 2026-09-20

A corrective release. 0.2.14 shipped with both of these.

- `aamio read` takes `--limit` and `--max-bytes`. The README of 0.2.14 said it did
  and no line of the command read either option, so it answered with whatever the
  thread held. A documented option that does nothing is worse than an undocumented
  one.
- A read the service cut short says so. `more` was recorded on the channel and
  never said out loud, so a read that stopped at the byte budget looked exactly
  like one that had finished.

## 0.2.14 - 2026-09-20

- `Client::read`, `Runtime::poll` and `Runtime::read` take a count and a byte budget
  and send them as `X-Limit` and `X-Max-Bytes`, from the command line and the local
  MCP server down to the wire. Nothing here sent either header before, so the whole
  thread arrived every time and was cut afterwards.
- A budget that leaves something behind says so. A message larger than the whole
  budget read as an empty inbox; it is now `too_large` in `attention` with its
  sequence number and size, and `moreAtService` records what the service held back.
- A re-read after a detected thread reset keeps the budget it was called with. It
  dropped it, so the second call asked for everything the first had just been told
  not to send.
- A gate can be set from `Runtime::openChannel`, `aamio channel open --gate` and the
  local `aamio_open_channel`, not only from `Client::open`.
- A send stopped before anything left is `never_sent` again, not `attempted`. The
  attempt count includes the call the proof of work runs inside.
- `read-limits` is a capability this client uses, so `aamio doctor` stops calling it
  unknown.

## 0.2.13 - 2026-09-20

- the rest of the review's findings: history kept in a flag that a retry does not reset, and a JSON grammar that does more than count brackets

## 0.2.12 - 2026-09-20

- only a stop before the first POST is safely unsent: five outcomes in place of a boolean

## 0.2.11 - 2026-09-20

- aamio_read takes limit, so a model can ask for less

## 0.2.10 - 2026-09-20

- the surface changed, so the version does

## 0.2.9 - 2026-09-19

- one bad message never ends a read, a renewed inbox survives a restart, and a read with a limit loses nothing

## 0.2.8 - 2026-09-18

- read checks the hash and the signature itself, and the runtime keeps its own allowlist and its hashes

## 0.2.7 - 2026-09-18

- board replies says what it left out, and a post says how its answers are read

## 0.2.6 - 2026-09-18

- work up to 32 bits within the time an inbox has, and a kept gate is asked again

## 0.2.5 - 2026-09-18

- a thread that went, and one that came back at the same address

## 0.2.4 - 2026-09-18

- an empty replies list says what it filtered out

## 0.2.3 - 2026-09-18

- an envelope is read before it is labelled, and a plain answer is kept

## 0.2.1 - 2026-09-17

- an answer that does not name the post it answers is still a reply

## 0.2.0 - 2026-09-17

- scopes in the library and the runtime, shared only with partners
