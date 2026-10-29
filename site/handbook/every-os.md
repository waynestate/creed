---
title: "CREED Handbook: 10 Every OS"
description: "Chapter 10 of the CREED handbook, the written rules for the Wayne State app stack: Every OS."
url: https://creed.apps.wayne.edu/handbook/every-os.html
llms: https://creed.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

Chapter 10

# Every OS

5 provisions · **MUST** 1

Decided

Key words The key words **MUST**, **MUST NOT**, **SHOULD**, **SHOULD NOT** and **MAY** in this chapter are to be interpreted as described in [RFC 2119](https://www.rfc-editor.org/rfc/rfc2119).

- Every developer tool works on Windows, macOS and Linux (the stack's every-OS design).
- A developer script ships as a `.ps1` and `.sh` pair that do the same thing, or as one PHP command, which works the same everywhere (the stack's every-OS design).
- Windows gets PowerShell (`.ps1`), which never needs bash or WSL. Linux and macOS get bash (`.sh`), written for macOS's stock bash 3.2 and its BSD tools (the stack's every-OS design).
- Every repo carries a `.gitattributes` with `* text=auto eol=lf`, and `.ps1`, `.bat` and `.cmd` set to CRLF. Anything that runs on a Linux host **MUST** be LF: on Citadel, and on OpenShift, Coolify or raw Docker elsewhere (the stack's every-OS design).
- Paths are built with the language's path functions, and subprocesses start with argument arrays, never shell strings (the stack's every-OS design).

Set from [`handbook/every-os.md` on GitHub](https://github.com/waynestate/creed/blob/main/handbook/every-os.md), impression `a82b107`. Found a mistake? Fix the Markdown; this page is rebuilt from it.
