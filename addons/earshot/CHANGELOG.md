# Changelog

aisenseapi/aamio-earshot, the add-on, called aisenseapi/aamio-sound up to 0.2.0. Dates are the day the version was
committed; it is tagged on release, and the two are the same day. Every entry
says what changed for somebody using it.

## 0.3.3 - 2026-11-12

- Nothing changed in this edition. The number is the add-on's, the same for
  aamio-python, aamio-php and Node: in the edition for aamio-python, a card
  whose time is far ahead stopped the listener on Windows, and 0.3.3 puts that
  right there. This edition heard such a card already.

## 0.3.2 - 2026-11-09

- When aisenseapi.com answers but cannot do the work, with a 503 or a 429, or
  cannot be reached, the sound is made or read is done on this machine as before, and the
  answer now says that the card or recording had gone to aisenseapi.com with the request,
  or may have. 0.3.1 said nothing went to it, also when aisenseapi.com had
  the whole card or recording before it answered 503. `fallback.sent` is `yes` when
  aisenseapi.com answered and `maybe` when the connection failed.
- `broadcast --out` writes the whole WAV or nothing. 0.3.1 did not check what
  fwrite took, and could answer `written` for a WAV the disk had cut short; a
  second try was then refused, as the file was there. Now a file that could not
  be written to the end is removed again, and the answer is `out_not_written`.
- A card heard by `listen` is judged by the clock when the recording ended, not
  when listening began, so a card that runs out while the microphone is open is
  turned down.
- A WAV with the WAVE_FORMAT_EXTENSIBLE header over 16-bit PCM, as many
  recorders write it, is read. It goes to aisenseapi.com with the same samples
  under the plain PCM header, the one aisense reads.
- An answer is written with U+FFFD for bytes that are not UTF-8, and one that
  cannot be written as JSON at all says so, rather than leave the core with
  nothing to show.

## 0.3.1 - 2026-11-09

- The README says how to enable the add-on under its new name, `vendor/bin/aamio addon enable earshot`.
  0.3.0 still said enable sound, which is no longer there, and opened with the
  old name.

## 0.3.0 - 2026-11-09

- aamio-sound is now aamio-earshot: the package, the command `aamio addon
  earshot` and the MCP tools aamio_addon_earshot_broadcast,
  aamio_addon_earshot_listen and aamio_addon_earshot_decode. Install it with
  `composer require aisenseapi/aamio-earshot` and enable it with `aamio addon enable earshot`.
  The card, the sound and the two engines are those of 0.2.0.

## 0.2.0 - 2026-11-08

- Two engines. `--engine local` (engine over MCP) makes and reads the sound on
  this machine, with aisense's own implementation of aisense-audio-v2, as a class of the add-on: the same sound byte for byte, read the same way, and
  neither the card nor the recording leaves the machine. aisense stays the
  default. When aisenseapi.com cannot be reached, the work is done here instead,
  and the answer says so with engine local and a fallback. Every answer says
  which engine did the work.

## 0.1.1 - 2026-11-08

- A card says until when it is good, t= in Unix seconds: an hour after it is
  made, unless `--valid-for` (valid_for over MCP) says otherwise, 60 to 86400
  seconds or 0 for no end. A listener turns down a card past its time as
  `expired`, exit code 3, with two minutes for clocks that differ, so a
  recording played again later is not taken for a fresh card. The time is not
  signed: a new scope for each occasion is still what stops someone who
  re-encodes the sound. 0.1.0 does not read a card with t, and says so.
- On Windows, the answer when nothing records says how to get SoX: winget
  install ChrisBagwell.SoX.

## 0.1.0 - 2026-11-08

- The first version. `aamio addon sound card`, `broadcast`, `listen` and
  `decode`, and the MCP tools aamio_addon_sound_broadcast,
  aamio_addon_sound_listen and aamio_addon_sound_decode. A card carries the
  write address of a scope, exactly 20 characters, and never a key. The sound
  is made and read by aisenseapi.com, and played and recorded by a program on
  the machine. On contract 2 of aamio's add-ons, and it needs
  aisenseapi/aamio 0.3.13 or later.
