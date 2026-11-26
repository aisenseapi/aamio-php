# Changelog

aisenseapi/aamio-qrcast, the add-on. Dates are the day the version was committed;
it is tagged on release, and the two are the same day. Every entry says what
changed for somebody using it.

## 0.2.1 - 2026-11-26

- The examples, the help and the manifest name the documentation address `00:00:5E:00:53:01` for Bluetooth
  instead of a real adapter's. Nothing else changed; the number is the same in all three runtimes.

## 0.2.0 - 2026-11-19

- The card is Earshot's card of 0.5.0: `--via` (`via` over MCP) names the
  media you prefer answers over, a transport's medium alone or with where you
  are reached on it, and a card that names one carries your public key, from
  `--key` on the command line and the runtime's own over MCP. Over MCP each
  medium gets where this machine is reached on it from the runtime's own
  transports. Only qrcast 0.2.0 and earshot 0.5.0 and newer read such a card;
  without `--via` and `--key` it is the card earlier versions made. Needs
  aisenseapi/aamio 0.4.1.
- A card seen with media and a key says how to bind a transport to it, with
  `aamio partner add` and `aamio transport bind`, with `--address` when the
  card says where, and binds nothing itself.
- Over MCP the reader names the transport to bind: the first medium on the
  card that one of this machine's transports carries, under that transport's
  name, so nobody has to know which transport carries which medium. When none
  does, it says so. On the command line TRANSPORT stands for it, as before.
- Over MCP a medium whose transport on this machine gives no address, as
  sound, goes on the card alone, and the note says why rather than what to
  put right.

## 0.1.3 - 2026-11-14

- zbarimg, ffmpeg and the openers of a picture start by their full path:
  `cmd` from the folder Windows keeps it in, and `open`, `sh` and `xdg-open`
  from the folders `PATH` names by a full path, never the working directory.
  `cmd`, `open` and `sh` started by the bare name, which Windows looks for in
  the working directory first. winget's link folder is looked in only through
  a `LOCALAPPDATA` that is a full path.

## 0.1.2 - 2026-11-12

- An unknown call is said as the editions for Python and Node say it: a name
  as it is, and anything else as JSON. The kind and the name were in quotes.
- The number is the add-on's, the same for
  aamio-python, aamio-php and Node: in the edition for aamio-python, a card
  whose time is far ahead stopped the reader on Windows, and 0.1.2 puts that
  right there. This edition read such a card already.

## 0.1.1 - 2026-11-09

- When aisenseapi.com answers but cannot do the work, with a 503 or a 429, or
  cannot be reached, the code is made or the picture read is done on this machine as before, and the
  answer now says that the card or picture had gone to aisenseapi.com with the request,
  or may have. 0.1.0 said nothing went to it, also when aisenseapi.com had
  the whole card or picture before it answered 503. `fallback.sent` is `yes` when
  aisenseapi.com answered and `maybe` when the connection failed.
- A code that is no card, with a letter such as ø across its 300th byte, no
  longer costs the whole answer. 0.1.0 cut the text it reports at 300 bytes,
  the cut could split the letter, and the answer came out empty: the core said
  the add-on had failed and to reinstall it. The text is now cut at 300
  characters, as the Python add-on cuts it, bytes that are not UTF-8 become
  U+FFFD, and an answer that cannot be written as JSON at all says so.
- `show --out` writes the whole file or nothing. 0.1.0 did not check what
  fwrite took, and could answer `written` for a file the disk had cut short; a
  second try was then refused, as the file was there. Now a file that could not
  be written to the end is removed again, and the answer is `out_not_written`.
- A card is judged by the clock when its frame or picture was read, not when
  the call began, so a card that runs out during a scan of up to a minute is
  turned down.
- A camera that stops giving pictures during `scan`, unplugged or taken by
  another program, is `camera_failed` with exit code 5. 0.1.0 answered
  `nothing_seen`, as if the scan had run its time and seen no code.

## 0.1.0 - 2026-11-09

- The first version. `aamio addon qrcast card`, `show`, `read` and `scan`, and the
  MCP tools aamio_addon_qrcast_show, aamio_addon_qrcast_read and aamio_addon_qrcast_scan.
  A card carries the write address of a scope, exactly 20 characters, and
  never a key: the earshot add-on's card, with its time limit. The code is made
  on this machine, at error correction level M with a quiet zone of four
  modules, the same code module for module as aamio-qrcast for Python, and drawn
  as PNG, SVG or text. zbarimg reads pictures and camera frames here, and
  ffmpeg takes the frames. aisenseapi.com makes and reads the code when engine
  aisense is asked for, and is never sent a frame from the camera. On contract
  2 of aamio's add-ons, and it needs aisenseapi/aamio 0.3.13 or later.
