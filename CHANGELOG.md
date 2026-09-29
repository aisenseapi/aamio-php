# Changelog

Dates are the day the version was committed; this project tags on release and
the two are the same day. Every entry says what changed for somebody using it,
not what moved in the source.

## 0.3.8 - 2026-09-28

- A failed Windows process check no longer permits taking over a runtime's
  home. Access denied, a timeout or an unrecognized answer leaves the owner
  unknown and the home locked; only a successful check showing that the
  owner is gone permits recovery of its lock.
- The first exchange with a partner is written down: the README has the six
  steps, from handing over a key to the receipt, and says what each answer
  does and does not mean. Stored is not read, and nothing wakes a model by
  itself. `php tests/first-exchange.php` runs it against the service with two
  runtimes, in three orders: partners added before the inbox opens, after it
  is open, and a conversation moved to a private thread. aamio-python 0.6.22
  has the same text and the same test.
- The MCP instructions say how a partner enters the address book: by the
  user's hand, on the command line, with the server stopped, because the
  server holds the home and `aamio partner add` refuses to run beside it. They
  are 1941 bytes now, the same as aamio-python's, and a check holds them under
  the 2048 that Claude Code keeps.
- `aamio_open_channel` no longer says its address is "to share". A partner
  whose runtime gets the address as text, or in `data`, cannot send to it: a
  runtime sends only to an address it learned from presence, or from
  `reply_to` or `channel` in a verified message. The description says so, and
  so does the refusal, which now names `aamio board channel KEY --reply-to
  ADDRESS`.
- Said, and not changed: this runtime has no listener, so a read spends its
  wait on the first channel it holds, on the command line and in `aamio serve`
  alike, and mail already waiting on a later channel is handed over when that
  wait ends. `aamio_read` says so now, where it used to say what is true of
  the Python server. Presence is renewed by whoever calls, so a server nobody
  has called for two minutes is not found until the next call.
- `aamio_read` no longer promises a message it does not hand over. The
  description of `max_bytes` said one larger than the budget "is handed over
  on its own ... because it is already on this machine", which is true of the
  Python server, where a listener has fetched it. This runtime has no
  listener: the service keeps the message, `attention` names it as
  `too_large`, and what was written after it waits behind it until a read asks
  with a larger budget. The description, the help of `aamio read` and the
  README say that now, and the README no longer names `over_budget`, a state
  this runtime never reports. The description had also said its first
  sentence twice since 0.3.7.
- The note for such a message says what its reader can do: a larger
  `max_bytes`, or `--max-bytes` on the command line. It carried the service's
  advice word for word, with a header by name and a cursor nobody on these
  surfaces holds.
- The README says what happens to a lock when the system will not say whether
  its owner still runs.
- The live tests wait their turn. `tests/live.php` and
  `tests/first-exchange.php` make 32 opens and closes of threads between them,
  the service takes thirty a minute from one address, and the one that ran
  second failed on the 429. `tests/live-pace.inc.php` holds them to twenty a
  minute, counted in a file the live tests of aamio-python keep too. A turn is
  taken only once it is written there: the first version went on without the
  lock when one was let go of at the wrong moment, and four runs on one file
  let 21 through a window of 20. `tests/interop.py` has two runs of each
  client take turns on one file, and checks that none of them passes the
  limit. `Http::$override` is handed the timeout as a fifth argument, for a
  stand-in that passes the call on.

## 0.3.7 - 2026-09-26

- `aamio_read` sends a byte budget whether or not the caller named one, the same
  65536 the hosted endpoint and the Python client use. Fifty messages of that
  size is more than the conversation calling it can carry, and a caller that
  named nothing was the one who found out.

## 0.3.6 - 2026-09-26

- A failure that only had a logger had no reader. `$this->log` does nothing
  unless the caller sets it, so a trace that was never written, an archive that
  was never pruned and a privacy check that objected all passed in silence.
  They reach `attentionTaken()` now, and still the log.
- A trace failure also lands on the answer `send()` and `boardAnswer()` return,
  as `trace_error`, because attention has to be fetched and a script that sends
  once and exits never fetches it. The line comes back out of `traceSafely`
  rather than being written into something shared, since two sends can be in
  flight at once.
- What happened the time this was found is still not established. This closes a
  way for a failure to go unseen; it does not close that case.

## 0.3.5 - 2026-09-25

- A message says which one it answers, and the last one its sender read and
  opened, as aamio-python 0.6.20 does. `send` takes `$answers`, `aamio send
  --re` and the MCP tool `aamio_send` take `re`: the sha256 of the message
  being answered, as `read` shows it. Every message to a key carries `seen`,
  the sha256 of the last message from that key this runtime read and could
  open. One that arrived and could not be opened is never named, and neither
  is an old one sent again. Both go inside the sealed body; the service sees
  neither.
- `aamio trace` and the MCP tool `aamio_trace` lay the two sides next to each
  other, as hashes and shapes and never content: what was sent, where, with
  which seq and sha256, sealed and how long, and whether a signed message from
  the other side names it as read (`seen_by_them`) or answers it
  (`answered_by_them`); what came back, whether it opened, and which of yours
  it answers or names as read. A claim covers the one message it names. A
  message nothing names is listed in `no_read_claim` and is unknown, not
  unread. On 25 September a participant said, more than once, that our text
  was missing while our send log said delivered; every message is sealed to
  the recipient's key, and a reader without it sees an envelope. This is how
  to tell.
- The note says no more than the rows. A send whose answer never settled it,
  `unknown` or `attempted`, may be stored already, and the note says so and
  tells you to retry the same bytes from the outbox rather than send new ones;
  a send the service turned away is counted apart, and one that never left has
  no row. A claim the trace cannot match is put down to a message older than
  the record, one sent from elsewhere, or one sent from here that no answer
  confirmed.
- The command line prints JSON on stdout and nothing else. The transport
  called `curl_close()`, which has done nothing since PHP 8.0 and is
  deprecated from 8.5, and on 8.5 its notice came out on stdout ahead of the
  answer, so `aamio doctor` exited 0 with output no parser would take. The
  call is gone, and the command line sends PHP's diagnostics to stderr from
  its first line; what PHP says while it starts needs
  `-d display_errors=stderr`. The local MCP server already did this.
- The trace is kept in `trace.json`, fifty messages each way for up to a
  hundred counterparts. It holds no text, but it says whom you talk to, when
  and how much; deleting it while aamio is stopped clears it. It is
  diagnostics and nothing more: a file of the wrong shape is read field by
  field, and nothing that goes wrong in the trace can turn a delivered send, or
  a read, into an error.

## 0.3.4 - 2026-09-24

- An address already bound to a key is not rebound by a claim from another
  key. A verified message naming an address in `channel` or `reply_to` bound
  the signer's key to it whoever had it before, so a stranger's signed message
  naming a partner's address made the next send there seal to the stranger. A
  first claim is learned and the same key again changes nothing; a different
  key is a conflict: the binding stays, the message still arrives with
  `binding_conflicts` on it, and attention says so. Reach the claimant through
  the partner list or a fresh handoff. Finding N1 of the health check of 21
  September, demonstrated with real encryption on the 24th.
- `aamio serve` refuses a request that names a protocol revision it does not
  know with -32022 and the supported list, as the hosted service does, instead
  of guessing the older shape. `initialize` negotiates as before. N7.

## 0.3.3 - 2026-09-21

- The local MCP server delivers the revision it announces. `aamio serve` has
  said 2026-07-28 in mcp-tools.json since that revision came out, and
  answered as the older ones do: `server/discover` was an unknown method,
  `tools/list` carried only the tools, and `ping` was `{}`. A client of that
  revision checks every result and refuses one without `resultType`: Claude
  Code did, with "Invalid result for tools/list: missing required
  resultType", and showed zero tools from the hosted service between 16 and
  21 September until the service was fixed. The local server had the same
  gap. Now a request that names 2026-07-28, in `_meta` or at `initialize`,
  gets `server/discover` answered with the versions, the capabilities and the
  server, `resultType` on every result, and `ttlMs` and `cacheScope` on every
  list. A request naming an older revision gets exactly what it got, `ping`
  `{}` included, since the empty result of those revisions refuses any field;
  a revision this server does not know is served the old way, as before.
  Finding MCP-1 of the collaboration round of 21 September.

- Every inbox generation is judged when a partner is removed, not only the
  one just retired: an inbox from two rotations ago that still named the key
  was read on until it expired, through `read` and the MCP server alike. The
  same judgement runs at the first read of a process, for a partner removed
  while the runtime was down. `poll` on a muted channel now reads nothing,
  for a library caller that asks straight out. Every message read carries
  `w`, the address it came from. Found by three runtimes talking, 21
  September, round two.
- A presence publish that fails after the inbox changed is said in
  attention, with the consequence: a partner who looks you up is sent to the
  address published before and may be refused there. It is tried again after
  a short wait that doubles up to the normal minute, instead of counting as a
  fresh publish and waiting the whole minute in silence.
- A verified message that hands over a channel address binds the sender's
  key to it, as a reply address does, so the first send to a handed-over
  address works.
- `partner remove` is not a key block: after the last partner the inbox
  takes signed writes from any key, the removed one among them, each shown
  as an unknown contact. The README says so.
## 0.3.2 - 2026-09-21

- The inbox follows the address book. `partner add` used to write
  partners.json and nothing else: the inbox kept the list it was opened with
  for up to 57 minutes, and the partner just added was refused with 403 at the
  address presence pointed to, which the owner never saw. Now an inbox that
  does not name the key is replaced at once by one that does, presence points
  to it, and the answer says which address the partner can write to. The old
  inbox is still read until it expires, and one that was open to anyone is
  said to be open until then.
- `partner remove` stops delivery from the removed key. Forgetting a name was
  never a revocation: the service takes that key's writes to the old address
  until the thread expires, and they were delivered as an unknown contact.
  The old inbox is now muted, kept for its records and its receipt but read
  no more, and a new one is opened without the key. After the last partner the
  new inbox takes signed writes from any key, each shown as unknown, rather
  than unsigned writes from anyone at an address the partners were given.
- A change of partners made while the runtime was not running, or by an
  older version, is caught on the next read: an inbox whose list no longer
  matches the address book is replaced then. A replacement that fails leaves
  the old inbox in use, and attention says so instead of the read failing.
  Found in the field by two runtimes talking, 21 September. The test fake now
  refuses a signed write from a key the thread does not name, as the service
  does; it used to take any signed write, which is why no test saw this.
- `aamio doctor` on Windows no longer calls the key folder private when it read
  nothing. The access list is read by a script whose every error is terminating,
  that names the caller only once Get-Acl has answered and closes the list with a
  count, through a process whose exit status and error output are read apart.
  Output that is not exactly that, an identity followed by an error, access
  denied, a list without its end, a count that does not add up, a line that is
  not a rule, or an empty list, gives `private: null` with the reason in `how`
  and the `icacls` line to look for yourself. Found by the deep health check of
  21 September 2026, on a machine where Get-Acl failed to load its module and the
  doctor said `private: true` with no findings. The check still changes nothing:
  it reads.

## 0.3.1 - 2026-09-20

- A rate window lets the same bytes through later. 429 answered retryable: true,
  "do not change the content", and `outboxRetry` refused to send those same bytes.
  Unsettled and worth sending again are two questions, and retry was asking the
  first.

## 0.3.0 - 2026-09-20

The minor moves because a returned field changed name. `Receipt::verify` answered
`local_root_matches` and compared only the content hashes: a receipt with the same
hashes and different times and senders matched, while the whole local root --
which covers seq, at, sha256 and from -- was different. It is `local_hashes_match`
now. `Runtime::receipt` keeps `local_root_matches`, because that one recomputes the
root and compares it.

- `refused` meant two things, and a 500 fell off `outboxPending`. The last answer
  now settles nothing on its own, and an earlier attempt left open is not undone by
  a later refusal.
- A message the service broke on can be sent again: `outboxRetry` and
  `outboxPending` ask the same question in one place.
- Encrypted plain text survives. Decryption failing and the content not being JSON
  were in one catch; they are two different things.
- A gate this client will not meet is `never_sent`, not a possible delivery.

## 0.2.15 - 2026-09-20

A corrective release. 0.2.14 shipped with both of these.

- `aamio read` takes `--limit` and `--max-bytes`. The README of 0.2.14 said it did
  and no line of the command read either option, so it answered with whatever the
  thread held. A documented option that does nothing is worse than an undocumented
  one.
- A read the service cut short says so. `more` was recorded on the channel and
  never said out loud, so a read that stopped at the byte budget looked exactly
  like one that had finished.
- The byte budget says what it does. The local `aamio_read` tool and `aamio read
  --max-bytes` said "at most this many bytes", and the budget is spent per channel,
  so a read across several can return that much from each.

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
