# Changelog

aisenseapi/aamio-qrcast, the add-on. Dates are the day the version was committed;
it is tagged on release, and the two are the same day. Every entry says what
changed for somebody using it.

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
