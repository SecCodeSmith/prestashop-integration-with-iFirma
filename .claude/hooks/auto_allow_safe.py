#!/usr/bin/env python3
"""Auto-allow PreToolUse hook for the Claude Code Bash tool.

Policy table (the hook answers "allow" or stays silent; it never denies;
any exception falls through to the normal permission prompt):

  Class                     Examples                                Decision
  ------------------------  --------------------------------------  --------------------------------
  pure readers              cat grep head tail sed -n ls find       allow if every path is read-safe
  git read subcommands      git status / log / diff / show          allow, paths read-safe
  git light writes          git fetch --prune, git stash list       allow only the listed forms
  project/scratch writes    > build/x.log  mkdir -p  touch          allow if target is write-safe
  project tooling           openspec and graphify: an ALLOW list of    allow, path args read-safe
                            subcommands (round 7), e.g. graphify query/
                            explain/path/update/build/report, openspec
                            list/show/status/validate/archive ...      (everything else prompts)
                            npm run build|build:zip|build:tgz|clean|
                            verify:package|check:license, npm ls  (npm view/info/search prompt)
  assignments               S=build; cat $S/x   export W=classes       allow, value judged first (round 7)
  curl (GET/HEAD only)      mock servers localhost/127.0.0.1 :8001 and :8002: any path and query
                            other local ports: only / and /docs; GitHub API/raw; no URL globbing
  project pytest            python -m pytest, python3 -m pytest, pytest, the project's
                            .venv/Scripts/python.exe -m pytest      allow, paths read-safe, report
                            and temp options write-safe (no -B, cache or basetemp needed)
  ps tasklist netstat       ps aux, ps -ef, ps -o pid,cmd           allow (options, not paths)
  everything else           rm cp bash python x.py > x.php          PASS (normal prompt)

READ-SAFE  = inside PROJECT or inside the scratchpad root (TEMP/claude), or /dev/null.
WRITE-SAFE = inside PROJECT/build or the scratchpad root, or /dev/null. Touch,
             mkdir and redirects all use this one rule.
PROJECT    = CLAUDE_PROJECT_DIR when set, else the folder two levels above this file.

Every path is resolved with os.path.realpath before any containment test, so
junctions, symlinks and trailing-dot Windows names (".git.") are judged where
they really point. Paths are compared case-insensitively.

Tokenizer rejects (PASS): unbalanced quotes, $( and backticks, <( >( process
substitution, lone &, heredocs and here-strings, grouping ( ) { } and keywords,
leading NAME=value (a standalone NAME=VALUE statement is the one exception, round 7), variables
other than TEMP TMP PWD, $? and variables assigned earlier in the same command, brace expansion with
commas, tilde expansion, control characters, commands over 6000 characters.

Glob rules: an unquoted glob word is judged by what it can expand to. A word
with a component that starts with '-' is rejected (bash turns -* into option
names). A glob whose component starts with a wildcard is expanded at check
time and every match must be read-safe and must not start with '-'. A dash
file name can only be created by a write, and every write target whose name
starts with '-' is rejected, so a chain cannot plant one for a later glob.

cd rule: cd is honoured only when it begins a ';' or newline group, or follows
'&&' directly after a cd that was itself honoured (so 'cd build && cd ..' works
and 'false && cd build' does not). It is not followed by '|' or '||', and it
names an existing read-safe directory with no link on the way (a junction
would make the shell's lexical cd and a physical walk disagree). Anything else
could leave the tracked cwd wrong.

Round-5 closures:
  - a '..' that follows a junction or symlink rejects, whatever the folder
    (Git Bash climbs from the link's place, Win32 from its target; the two
    must not be compared, so the hook refuses the case).
  - an attached short option value is checked as a whole path (every tail
    that holds a separator or a drive), so grep -fJ../.. and git blame -S
    cannot drop a leading character; grep -f FILE makes every operand a file.
  - the chain-wide read/write rule: no file or folder is both read (operands,
    < inputs, glob matches) and written (> >> &> targets) anywhere in one
    command line, so no write can feed its own input. Declined: reading a
    file after writing it in the same chain, and ls DIR > DIR/x.
  - a folder search (grep -r) with a write target inside that folder rejects
    for the same reason.
  - curl paths take only A-Za-z0-9._~/- (';' path parameters, '%' and '\'
    are refused, and dot segments too).
  - tar takes the old style too: a first word without a dash is a cluster of
    option letters, so 'tar xf a -t' rejects on x.

Round-2 closures: every unquoted brace is refused (brace expansion can build
any option name), printf takes no options and no 5+ digit numbers, a recursive
walk (grep -r, find, rg, tree, du, ls -R) checks every link and junction below
its folder and is bounded, a scheme://host argument is refused, npm and python
never run from build/ or scratch (package.json and sys.path lookups), graphify
and openspec run only with read-safe path arguments (round 6 lifted the
read-subcommand list), and an awk '>' counts as output
only inside print or printf.

Round-3 closures: $[ (old arithmetic expansion, which can assign variables) is
refused like $(; bracket expressions take only plain characters and ranges (no
POSIX classes, no ^ negation, no dots), so bash and Python glob match the same
files; pytest takes only the read-only options in PYTEST_LONG_OK and
PYTEST_SHORT_OK (no --pastebin, --cache-dir, --cache-clear, --durations-path or
plugin options, abbreviations included); curl -w accepts only %{name} variables
from CURL_WRITEOUT_VARS and %%; git fetch's first word must be a remote that the
repository configures; diff and git diff --no-index walk directory operands like
grep -r; jq refuses modulemeta and get_search_list; seq estimates each
conversion's width and precision; npm names may not start with a dash.

Round-4 closures (the red team's bypass list, each closed as a class):
  - paths resolve component by component (a '..' after a junction goes to the
    junction target's parent, as the shell does); names of Windows devices
    (CON, PRN, AUX, NUL, COMn, LPTn, with any extension) are never files; a
    /cygdrive/<drive>/ prefix is read as /<drive>/; a glob followed by '..' rejects.
  - a folder with HEAD, objects/ and refs/ is a bare repository to git, so git
    refuses to run from it when it lies in build or scratch.
  - tree takes only an exact long-option list (no --fromfile); ls treats
    --recursive and its abbreviations as -R; 7z refuses @listfile words.
  - awk: no loops or user functions, a for needs a condition and a step, at most
    8 print statements, and a program that mixes quotes and slashes is refused.
  - jq: no repeat, while, until, recurse, range or '..', no five-digit numbers.
  - sed: at most 8 commands, replacements up to 256 characters, and no H with a
    command that prints the hold space.
  - pytest: [superseded by round 6] only python -B -m pytest with -p
    no:cacheprovider and --basetemp inside build or scratch.
  - graphify update is refused [superseded by round 6: allowed, graphify-out/ is an
    accepted write target].
  - curl to localhost: GET of /, /docs and /__mock/status only; no query
    strings, no dot segments, no percent-escapes. [Round 6 widens the two mock
    ports 8001 and 8002 only.] The shop module controllers (cron, receipt,
    datarequest) still prompt.
  - declined and left as they were: git help (starts a pager or a browser),
    git config --global reads (the contract allows them; they read outside the
    project but change nothing). npm run build is allowed since round 6.

Round-6 (lead decisions, each relaxation has a test and a dangerous sibling):
  - pytest: python -m pytest, python3 -m pytest, bare pytest and the project's
    .venv/Scripts/python.exe -m pytest all run, with or without -B, with every path
    read-safe and outside build/scratch (bare pytest at the project root still prompts:
    it would collect build/). .pytest_cache and __pycache__ writes in the project are
    accepted. Options that write a path (--basetemp --junitxml --junit-xml --html
    --report-log --log-file --cache-dir --cov-report DEST) need a write-safe value,
    deliberately stricter than "read-safe": a report must not overwrite a project file
    and pytest clears its --basetemp. -c --rootdir --confcutdir --config-file need a
    read-safe path outside build/scratch. -p takes only no:NAME. -o takes only the keys
    in PYTEST_INI_SAFE (addopts would bypass every option check). --pastebin and all
    other unlisted options (plugins, abbreviations) still reject. A node id
    (file.py::test_x) is judged by the part before '::'.
  - git grep: -c after the subcommand (also -ci, -cn) is the count flag; -c before it
    is a config override and rejects. Other subcommands still reject -c.
  - npm run build, build:zip, build:tgz and clean are allowed (dist/ and *.tgz in the
    project root are gitignored outputs the owner accepts). Nothing else new.
  - graphify and openspec: every subcommand runs when its path arguments are read-safe
    and no argument is a URL. [Superseded by round 7: an ALLOW list of subcommands.]
  - curl: GET/HEAD of http://localhost|127.0.0.1:8001|8002 takes any path and query,
    except a URL containing '..', ';', an encoded slash/dot (%2f %2e) or a backslash. Other
    local ports keep the /, /docs, /__mock/status rule (the shops mutate state on GET).
  - $? is the one special parameter allowed. It is a number of unknown value, so it may
    only be an argument of echo or printf (never a path, redirect target or command).
  - ps takes option words and id/column lists only (aux, -ef, -o pid,cmd), no paths.
  - the decision log build/auto-allow.log is rotated to auto-allow.log.1 above 1 MB
    (best effort, never raises).
  - all recursive walks (grep -r, find, rg, tree, du, ls -R, diff, git diff --no-index)
    share ONE time budget of WALK_TIME_BUDGET (150 ms) per classify() call; a folder is
    walked once per call. When it runs out the decision is PASS "walk budget exceeded";
    an incomplete walk never allows.

Round-7 (final red-team pass, each closed as a class; every probe is a regression case):
  - pytest: any word starting with '@' (argument file, any position, also after --) rejects.
  - test and [: -v and -R reject (bash evaluates a[$(cmd)] arithmetically), as does any operand
    holding [, $( or a backtick, even in quotes.
  - graphify and openspec switched from a deny list to an ALLOW list. graphify: query explain
    path update build report, --help -h --version and '<sub> --help'; options whose name holds
    out output dir backend postgres workspace provider install hook memory label merge reject,
    as does any URL or host= value; update/build paths must be inside PROJECT. openspec: list
    show status instructions validate context new(change) archive sync update schemas
    schema list|show store list|show doctor and the help/version forms; --force and
    --code-workspace reject everywhere.
  - date: every operand must be +FORMAT (a bare operand sets the clock); -s, --set, -f and
    --file reject.
  - git config: no --global, --system, --show-origin, --show-scope, --file/-f, --blob or
    --worktree; --list and --get-regexp need --local; a single-key read stays allowed.
    git var is not allowed.
  - npm view, info, show and search prompt (they contact the registry with a chosen name).
  - the decision log build/auto-allow.log and auto-allow.log.* is never a write target (any
    case, trailing dots, ':stream'); reading it stays allowed.
  - curl: no [ ] { } in a URL (URL globbing), --retry <= 5, --max-time/-m <= 600,
    --connect-timeout <= 60 (numbers only), no -H header whose name holds 'method', a port
    must be 1..65535 and match 8001/8002 exactly for the any-path rule, other local ports
    allow exactly / and /docs (optional trailing slash).
  - TEMP/TMP: the scratch root exists only when the raw value is an absolute drive path whose
    last component is Temp or tmp (or that lies under %LOCALAPPDATA%), is not a drive root,
    not under Windows or Program Files, and not an ancestor of PROJECT. Otherwise only
    PROJECT (and build for writes) and /dev/null are safe.
  - cwd must be an absolute path string (drive or /c/ form); 5, ["x"], "build", "" and a missing
    cwd in hook mode all PASS. classify(cmd, None) still means PROJECT (developer mode).
  - never-returning or exhausting commands: tail -f/-F/--follow (any spelling), sleep (no longer
    a head), sort -S/--buffer-size/--batch-size/--parallel; jq: def, combinations, infinite, nan,
    exponent numbers, 5+ digit numbers, more than one '*'; awk: for loops with a constant
    condition, a step that does not move the condition, or a huge bound; printf widths that are
    '*' or 5+ digits (judged on the string literals, which the older check never saw); a printf
    format that is not a literal; -v/operand/program assignments of 5+ digit numbers; a variable
    assigned from itself twice (s = s s) or a pure concatenation of variables that includes the
    target (a = a b); sed: G together with h, H or x (doubling).
  - glob expansion is done by this module, level by level, under the shared walk time budget
    (0.400 s) and a cap of 5000 entries; either limit makes the decision PASS.
  - over-blocks fixed: git log/diff/show -c and -cc (display), the three --output-indicator-*
    options (exact names), findstr and where switches (/i, /c:TEXT; findstr /s and where /r get
    the link walk), sed y///, mkdir without -p (every target write-safe, -m still rejects),
    touch -- FILE, and git for-each-ref/log/branch/tag --format=%(refname) with the parentheses
    inside one --format= word.
  - RELAXATION: a standalone NAME=VALUE or export NAME=VALUE statement (own segment, followed by
    ';', '&&' or a newline, reached unconditionally: first, after ';' or newline, or after another
    assignment joined by '&&') defines a variable for the later commands of the same line. NAME
    must not be a shell, loader or tool variable (PATH IFS HOME TEMP ..., any LD_ GIT_ PYTHON
    NODE_ NPM_ BASH_ CURL_ ... prefix, compared in upper case); VALUE is a literal of at most 300
    characters without $, backtick, whitespace, globs, ~, quotes or control characters, and is a
    read-safe path or a plain [A-Za-z0-9_.:-] token. The lexer leaves a placeholder for $NAME,
    ${NAME} and "$NAME..."; _substitute_seg swaps the value in before every other check, so
    '$S/x' is judged as the path it names. $NAME as the command name, inside a sed, awk or jq
    program (only a file operand '$NAME' or '$NAME/path' is allowed), unassigned, or assigned
    conditionally rejects. At most 8 variables per command.

Long-option rules: sort, sed, date, hostname, file, uniq and tree use an
explicit option allow list (an unknown or abbreviated name rejects). Other
heads reject any long option that is a prefix of a dangerous option (argparse
and getopt accept unique abbreviations). The git heads also reject
abbreviations of --output, --open-files-in-pager and the like.

How to extend: add the head to ALLOWED_HEADS, add a checker in _run_head (or a
new function), then add table cases to test_auto_allow_safe.py. Keep the
tokenizer rejections as they are.

Developer mode:
  python -I auto_allow_safe.py --check "<command>" [--cwd DIR]
prints "ALLOW <reason>" or "PASS <reason>" and exits 0 for ALLOW, 1 for PASS.

Hook mode: Claude Code sends the event JSON on stdin. An ALLOW prints one
hookSpecificOutput JSON object; a PASS prints nothing. Exit code is always 0.

ALLOW_PYTHON_INLINE_READONLY (module constant, shipped False): when True,
`python -c CODE` is allowed if CODE imports only json sys re pathlib
collections itertools math datetime textwrap pprint csv, calls open() only
with a read mode, and uses none of the write or exec tokens listed in
PY_INLINE_BAD. Shipped False, so inline python always prompts.
"""
from __future__ import annotations

import argparse
import json
import ntpath
import os
import re
import sys
import tempfile
import time
from datetime import datetime
from fnmatch import fnmatchcase
from pathlib import Path

# ---------------------------------------------------------------- constants
ALLOW_PYTHON_INLINE_READONLY = False
MAX_COMMAND_LEN = 6000
WRITE_SAFE_SUBDIRS = ('build',)
DEVNULL = '/dev/null'
LOG_NAME = 'auto-allow.log'
ALLOWED_VARS = frozenset({'TEMP', 'TMP', 'PWD'})
CONTROL_RE = re.compile(r'[\r\x00-\x08\x0b-\x1f]')
# A bracket expression is [...] with no space, quote or operator inside. Its body
# may only be plain characters, ranges and a leading '!'. POSIX classes ([:alpha:]),
# '^' negation, '=' and '.' forms are refused: bash reads them differently from
# Python's glob, so a checked pattern could match something else at run time.
GLOB_BRACKET_RE = re.compile(r'\[([^\s\'"\\`$;&|<>()\]]*)\]')
GLOB_BRACKET_BODY_OK = re.compile(r'!?(?:[A-Za-z0-9_]|[A-Za-z0-9]-[A-Za-z0-9])+')

PURE_READ_HEADS = frozenset({
    'cat', 'tac', 'nl', 'head', 'tail', 'wc', 'cut', 'tr', 'rev', 'column',
    'fold', 'paste', 'comm', 'diff', 'cmp', 'stat', 'du', 'df', 'ls',
    'basename', 'dirname', 'realpath', 'readlink', 'md5sum', 'sha1sum',
    'sha256sum', 'od', 'strings', 'seq', 'pwd', 'true', 'false', 'echo',
    'printf', 'test', '[', 'whoami', 'uname', 'id', 'which', 'type', 'where',
    'jq', 'grep', 'egrep', 'fgrep', 'zgrep', 'findstr', 'ps',
    'zipinfo', 'openspec', 'graphify',
})
SPECIAL_HEADS = frozenset({
    'sort', 'uniq', 'date', 'hostname', 'file', 'xxd', 'tree', 'find', 'rg',
    'sed', 'awk', 'gawk', 'mawk', 'git', 'npm', 'node', 'npx', 'python',
    'python3', 'py', 'pytest', 'curl', 'mkdir', 'touch', 'unzip', 'tar', '7z',
    'tasklist', 'netstat',
})
ALLOWED_HEADS = PURE_READ_HEADS | SPECIAL_HEADS | frozenset({'cd'})
# Named here for documentation and clearer reasons; anything not in
# ALLOWED_HEADS is rejected anyway.
DENIED_HEADS = frozenset({
    'command', 'builtin', 'exec', 'eval', 'source', '.', 'env', 'nohup', 'nice',
    'time', 'timeout', 'watch', 'xargs', 'sudo', 'su', 'bash', 'sh', 'zsh',
    'cmd', 'cmd.exe', 'powershell', 'pwsh', 'wsl', 'wsl.exe', 'docker', 'perl',
    'ruby', 'php', 'cp', 'mv', 'rm', 'rmdir', 'ln', 'chmod', 'chown', 'chgrp',
    'dd', 'truncate', 'shred', 'tee', 'install', 'patch', 'kill', 'taskkill',
    'pkill', 'export', 'unset', 'ssh', 'scp', 'nc', 'wget',
})
SHELL_KEYWORDS = frozenset({
    'if', 'then', 'else', 'elif', 'fi', 'for', 'while', 'until', 'do', 'done',
    'case', 'esac', 'select', 'function', 'time', '{', '}', '!', '[[', ']]',
    'in', 'coproc',
})

# git
GIT_READ_SUBCOMMANDS = frozenset({
    'status', 'diff', 'log', 'show', 'ls-files', 'ls-tree', 'rev-parse',
    'rev-list', 'describe', 'blame', 'grep', 'shortlog', 'cat-file',
    'show-ref', 'for-each-ref', 'count-objects', 'check-ignore', 'check-attr',
    'diff-tree', 'diff-index', 'name-rev', 'merge-base', 'version',
    'cherry', 'whatchanged', 'range-diff',
})
# 'help' is not allowed: git help starts man, info or a browser (-w, -m, -i).
GIT_SPECIAL_SUBCOMMANDS = frozenset({
    'fetch', 'remote', 'branch', 'tag', 'stash', 'submodule', 'worktree',
    'reflog', 'config',
})
GIT_FORBIDDEN_PREFIX = (
    '--output', '--ext-diff', '--upload-pack', '--receive-pack',
    '--config-env', '--open-files-in-pager', '--git-dir', '--work-tree',
    '--exec-path', '--namespace', '--exec',
)
# Long options checked as prefixes both ways: "--ope" abbreviates --output.
GIT_DANGEROUS_LONG = GIT_FORBIDDEN_PREFIX
# Round 7: git log/diff display options whose names extend --output (they choose
# the marker characters of a diff and write no file). Exact names only.
GIT_DISPLAY_LONG = frozenset({
    '--output-indicator-new', '--output-indicator-old', '--output-indicator-context',
})
GIT_FETCH_FLAGS = frozenset({
    '--prune', '-p', '--all', '--tags', '--no-tags', '-t', '-q', '--quiet',
    '-v', '--verbose', '--dry-run', '--progress', '--no-progress',
})
# A remote name cannot start with '.', so "..", "." and paths are rejected.
GIT_REMOTE_NAME = re.compile(r'[A-Za-z][A-Za-z0-9_-]*')
GIT_BRANCH_FLAGS = frozenset({
    '-a', '-r', '-v', '-vv', '--list', '--show-current', '--merged',
    '--no-merged', '--contains', '--points-at', '--format',
})
GIT_BRANCH_LISTISH = frozenset({
    '--list', '--merged', '--no-merged', '--contains', '--points-at',
})
GIT_TAG_LISTISH = frozenset({'-l', '--list', '--contains', '--points-at'})
GIT_TAG_FLAGS = GIT_TAG_LISTISH | frozenset({'-n', '--sort', '--format'})
GIT_CONFIG_MODES = frozenset({'--get', '--get-all', '--get-regexp', '--list', '-l'})
# Round 7: no --global, --system, --show-origin, --show-scope, --file, --blob or
# --worktree (they read outside the project); list and regexp reads need --local.
GIT_CONFIG_FLAGS = GIT_CONFIG_MODES | frozenset({
    '--local', '--name-only', '-z', '--null',
})

# npm / node
# run build, build:zip, build:tgz and clean write dist/ and the *.tgz in the
# project root. Both are gitignored build outputs that the owner accepts.
NPM_EXACT = frozenset({
    ('run', 'check:license'), ('pack', '--dry-run'), ('--version',), ('-v',),
    ('run', 'build'), ('run', 'build:zip'), ('run', 'build:tgz'), ('run', 'clean'),
})
NPM_NAME_RE = re.compile(r'(?:@[A-Za-z0-9._~-]+/)?[A-Za-z0-9_~][A-Za-z0-9._~-]*(?:@[A-Za-z0-9._~^<>=*|-]+)?')
NPM_FIELD_RE = re.compile(r'[A-Za-z0-9_.-]+')

# curl. -L is not allowed: a redirect would send the request, and any -H
# header, to a host outside the list. No argument may contain '@' (that
# covers -H@file, -w@file and userinfo tricks); the URL must match exactly.
CURL_URL_RE = re.compile(
    r'https?://(?:api\.github\.com|raw\.githubusercontent\.com)'
    r'(?::[0-9]{1,5})?(?:[/?#][^\s\\@]*)?')
# Local app. The PrestaShop shops mutate state on GET (the module's cron,
# datarequest and receipt controllers), so on every local port except the two
# mock servers only the paths below are read, with no query string. The mock
# servers (8001, 8002) mutate only through POST and DELETE, so a GET or HEAD of
# any path and query string is read-only there (CURL_MOCK_RE).
CURL_LOCAL_RE = re.compile(r'https?://(?:localhost|127\.0\.0\.1)(?::([0-9]{1,5}))?(/[^?#\s\\@]*)?')
# Round 7: exactly / and /docs (one optional trailing slash), no sub-paths.
CURL_LOCAL_PATHS = frozenset({'/', '/docs', '/docs/'})
CURL_MOCK_RE = re.compile(r'https?://(?:localhost|127\.0\.0\.1):(?:8001|8002)(?:[/?][^#\s\\@]*)?')
# '..', ';' (path parameters), an encoded slash, backslash or dot could name a
# path other than the one written, so they stay refused.
CURL_MOCK_BAD_RE = re.compile(r'\.\.|;|%2[fFeE]|%5[cC]')
# Only plain path characters: ';' (Tomcat/Jetty path parameters) makes '..;' a
# dot segment on some servers, and '%' or '\' can spell a dot segment too.
CURL_PATH_CHARS_RE = re.compile(r'/[A-Za-z0-9._~/-]*')
CURL_NOARG = frozenset({'-s', '-S', '-f', '-i', '-I', '-G'})
CURL_NOARG_LETTERS = frozenset('sSfiIG')
CURL_VALUE_SHORT = frozenset({'w', 'H', 'm', 'A'})
CURL_LONG_NOARG = frozenset({'--compressed'})
CURL_WRITEOUT_VARS = frozenset({
    'http_code', 'response_code', 'http_connect', 'http_version', 'size_download',
    'size_upload', 'size_header', 'size_request', 'speed_download', 'speed_upload',
    'time_total', 'time_namelookup', 'time_connect', 'time_appconnect',
    'time_pretransfer', 'time_redirect', 'time_starttransfer', 'url_effective',
    'content_type', 'num_connects', 'num_redirects', 'redirect_url', 'remote_ip',
    'remote_port', 'local_ip', 'local_port', 'scheme', 'ssl_verify_result',
})
CURL_LONG_VALUE = frozenset({'--max-time', '--retry', '--connect-timeout', '--url'})

# Long-option allow lists (exact names; an abbreviation is not on the list,
# so it rejects). Short letters listed here are the only ones accepted.
SORT_LONG_OK = frozenset({
    '--ignore-leading-blanks', '--dictionary-order', '--ignore-case',
    '--general-numeric-sort', '--ignore-nonprinting', '--human-numeric-sort',
    '--month-sort', '--numeric-sort', '--random-sort', '--reverse', '--stable',
    '--unique', '--version-sort', '--zero-terminated', '--merge', '--check',
    '--key', '--field-separator', '--sort', '--debug', '--help', '--version',
})  # round 7: no --buffer-size (-S), --batch-size or --parallel (memory, files, threads)
SORT_SHORT_OK = frozenset('bdfghiMnRrsuVzcCmkt')
SED_LONG_OK = frozenset({
    '--quiet', '--silent', '--regexp-extended', '--null-data', '--zero-terminated',
    '--unbuffered', '--debug', '--posix', '--sandbox', '--line-length',
    '--separate', '--expression', '--help', '--version', '--follow-symlinks',
})
SED_SHORT_OK = frozenset('nEruzsbel')  # -i and -f are not in it, so they reject
DATE_LONG_OK = frozenset({
    '--utc', '--universal', '--date', '--iso-8601', '--rfc-3339',
    '--rfc-email', '--reference', '--debug', '--help', '--version',
})  # round 7: no --file (reads dates from a file) and no --set
DATE_SHORT_OK = frozenset('udIRr')
HOSTNAME_OK = frozenset({
    '-s', '-f', '-d', '-i', '-I', '-A', '-a', '--short', '--fqdn', '--domain',
    '--ip-address', '--all-ip-addresses', '--alias', '--help', '--version',
})
FILE_LONG_OK = frozenset({
    '--brief', '--mime', '--mime-type', '--mime-encoding', '--extension',
    '--apple', '--dereference', '--no-dereference', '--uncompress',
    '--separator', '--print0', '--keep-going', '--raw', '--special-files',
    '--no-buffer', '--preserve-date', '--no-pad', '--help', '--version',
    '--exclude', '--exclude-quiet', '--checking-printout', '--ignore-encoding',
})
FILE_SHORT_OK = frozenset('bizLhknNprsvFeE0Zdc')
# Short options that take a value (the value is the next word or the rest of
# the cluster). A letter wrongly listed here would hide a path word from the
# scope check, so this list matches pytest's own short options exactly.
PYTEST_SHORT_VALUE = frozenset('kmrW')
# pytest's own read-only options. Everything else is refused, which closes the
# whole class: unique abbreviations, plugin options, and options whose value is
# a path the hook cannot see (--pastebin, --cache-dir=, --durations-path=,
# --json-report-file=, --pdbcls=, --cache-clear, -p, -o, -c, -n ...).
PYTEST_LONG_OK = frozenset({
    '--co', '--collect-only', '--tb', '--maxfail', '--no-header', '--disable-warnings',
    '--showlocals', '--lf', '--last-failed', '--ff', '--failed-first', '--nf',
    '--new-first', '--strict', '--strict-markers', '--strict-config', '--color',
    '--durations', '--durations-min', '--verbose', '--quiet', '--exitfirst', '--capture',
    '--no-summary', '--keyword', '--markexpr', '--runxfail', '--setup-show',
    '--setup-plan', '--continue-on-collection-errors', '--version', '--help',
    '--fixtures', '--markers', '--assert', '--full-trace', '--show-capture',
})
PYTEST_SHORT_OK = frozenset('qvsxlhVrkmW')

RG_DENY_LONG = (
    '--pre', '--pre-glob', '--hostname-bin', '--hyperlink-format', '--generate',
    '--type-add',
)
TREE_SHORT_DENY = frozenset('Ro')
JQ_BAD_TOKENS = re.compile(r'\$ENV\b|\benv\b|\bimport\b|\binclude\b|\binput_filename\b|\$__prog|\bmodulemeta\b|\bget_search_list\b'
                           r'|\brepeat\b|\bwhile\b|\buntil\b|\brecurse\b|\brange\b|\.\.')
# jq generators with no end (repeat, while, until, recurse, .., range) and big
# numbers (a string times 100000) would print or allocate without bound.
JQ_DENY_LONG = ('--from-file', '--library-path')
SEQ_MAX_COUNT = 100000
SEQ_CONV_RE = re.compile(r'%[-+ #0]*(\d*)(?:\.(\d*))?')
# Options whose value is a name pattern only (grep --include=*.php). A glob may
# follow the "=" of these, because it cannot turn into a different option.
GLOB_PATTERN_OPTS = frozenset({'--include', '--exclude', '--exclude-dir', '--glob', '--iglob'})

# python inline (only used when ALLOW_PYTHON_INLINE_READONLY is True)
PY_INLINE_IMPORTS = frozenset({
    'json', 'sys', 're', 'pathlib', 'collections', 'itertools', 'math',
    'datetime', 'textwrap', 'pprint', 'csv',
})
PY_INLINE_BAD = frozenset({
    'write', 'remove', 'unlink', 'rmdir', 'rename', 'replace', 'rmtree',
    'system', 'popen', 'subprocess', 'exec', 'eval', 'compile', '__import__',
    'importlib', 'socket', 'urllib', 'http', 'requests', 'shutil', 'setattr',
    'globals', 'builtins', 'getattr', 'chmod', 'truncate', 'mkdir', 'makedirs',
    'symlink', 'link', 'environ', 'input', 'write_text', 'write_bytes',
    'touch', 'symlink_to', 'hardlink_to', 'spawn', 'fork', 'kill',
})

# awk / sed
AWK_LONG_OK = frozenset({'--field-separator', '--assign', '--source', '--posix',
                         '--traditional', '--version', '--re-interval',
                         '--characters-as-bytes'})
AWK_BAD_LETTERS = frozenset('fEilodpDW')
AWK_BAD_PROGRAM = ('|', 'system', 'getline', 'fflush', 'close', '@', 'ENVIRON', 'ARGV', 'ARGC', '/inet')
AWK_PRINT_RE = re.compile(r'\bprint(?:f)?\b')
AWK_STRING_RE = re.compile(r'"(?:\\.|[^"\\])*"')
SED_ALLOWED_CMDS = frozenset('pdq=lnNhHgGx')
SED_FLAG_CHARS = frozenset('gIip')

TASKLIST_OK = frozenset({'/v', '/svc', '/nh', '/fo', 'table', 'list', 'csv'})
# Only these long options exist for md5sum and friends; --check (and any
# abbreviation of it) reads the names listed in a file, so it is not here.
SUM_LONG_OK = frozenset({'--binary', '--text', '--tag', '--zero', '--ignore-missing',
                         '--quiet', '--status', '--strict', '--warn', '--help', '--version'})
# Heads that never read a file named in their words: echo prints them, tr maps
# characters, the rest take no file operands. Their words are not path-checked.
NO_PATH_HEADS = frozenset({'echo', 'true', 'false', 'whoami', 'uname', 'id', 'tr'})
# Heads whose operands are never read as file contents (the chain read/write rule
# skips them): the no-path heads plus these, whose operands are format or values.
STATUS_OK_HEADS = frozenset({'echo', 'printf'})
NO_READ_HEADS = NO_PATH_HEADS | frozenset({
    'printf', 'date', 'seq', 'pwd', 'hostname', 'mkdir', 'touch'})
# tree -- fromfile / --fromfile reads directory names from a file, so tree takes
# only these long options (an exact list, no abbreviations).
TREE_LONG_OK = frozenset({
    '--noreport', '--charset', '--filelimit', '--dirsfirst', '--filesfirst', '--si',
    '--du', '--prune', '--inodes', '--device', '--ignore-case', '--matchdirs',
    '--metafirst', '--timefmt', '--sort', '--help', '--version',
})
# Output amplification caps. There are no loops (see _awk_program_ok), so the
# number of print statements and sed commands bounds the output per input line
# (see _awk_structure_ok and _sed).
SED_MAX_COMMANDS = 8
SED_MAX_REPL = 256
AWK_MAX_PRINTS = 8
# Any scheme://host argument is network access (graphify add, openspec show).
URL_RE = re.compile(r'[A-Za-z][A-Za-z0-9+.-]*://')
# Bounds: the link check of a recursive walk, and the output size of seq.
WALK_MAX_ENTRIES = 60000
# One total time budget for all the recursive walks of one classify() call. An
# incomplete walk never allows: running out of time is a PASS.
WALK_TIME_BUDGET = 0.400
_WALK_TIME = [0.0]  # seconds spent walking so far in this classify() call
_WALKED = []        # folders (as _ident tuples) already checked in this call
LOG_MAX_BYTES = 1000000
SEQ_MAX_BYTES = 1000000
# grep-family option tables: (value letters, no-value letters) per tool. A letter
# in neither set makes the pattern position unknown (see _grep_like).
GREP_SHORT = {
    'grep': (frozenset('ABCDdmfXe'), frozenset('abcEFGHhIilLnNoPqRrsTUuvwxyZz')),
    'rg': (frozenset('ABCEMTdgjmrtfe'), frozenset('aFhHiIlLnNoPpqsSuUvwxz0bc')),
    'git': (frozenset('ABCef'), frozenset('IiwvEGFPnlLcoqhHWpza')),
}
GREP_LONG_PATTERN = frozenset({'--regexp'})
GREP_LONG_VALUE = frozenset({
    '--file', '--ignore-file', '--exclude-from', '--max-count', '--after-context',
    '--before-context', '--context', '--label', '--binary-files', '--devices',
    '--directories', '--exclude', '--include', '--exclude-dir', '--group-separator',
    '--glob', '--iglob', '--type', '--type-not', '--max-depth', '--max-columns',
    '--threads', '--encoding', '--engine', '--sort', '--sortr', '--color', '--colour',
    '--colors', '--context-separator', '--field-match-separator',
    '--field-context-separator', '--path-separator', '--replace', '--max-filesize',
    '--dfa-size-limit', '--regex-size-limit',
})
GREP_LONG_NOVALUE = frozenset({
    '--line-number', '--count', '--count-matches', '--ignore-case', '--invert-match',
    '--word-regexp', '--line-regexp', '--recursive', '--dereference-recursive',
    '--with-filename', '--no-filename', '--files-with-matches', '--files-without-match',
    '--only-matching', '--quiet', '--silent', '--null', '--null-data', '--text',
    '--initial-tab', '--perl-regexp', '--extended-regexp', '--fixed-strings',
    '--basic-regexp', '--no-messages', '--byte-offset', '--unix-byte-offsets',
    '--no-ignore-case', '--help', '--version', '--files', '--hidden', '--no-ignore',
    '--smart-case', '--no-heading', '--heading', '--json', '--stats', '--follow',
    '--no-follow', '--multiline', '--column', '--no-column', '--vimgrep',
    '--sort-files', '--unrestricted', '--pcre2', '--no-config', '--trim',
    '--case-sensitive', '--passthru', '--line-buffered', '--no-unicode',
    '--no-line-number', '--mmap', '--no-mmap',
})
NETSTAT_RE = re.compile(r'-[anoe]+')

# ---------------------------------------------------------------- paths
class Reject(Exception):
    """A rule failed; the message is the short reason shown in the PASS line."""


def _real(path, strict=False):
    """Absolute Windows path of a folder or file, with links followed.

    The shell and Win32 disagree on '..' after a link: Git Bash climbs from the
    link's own (lexical) position, while a physical walk climbs from its target.
    Round 5 showed that either choice can be turned into an escape, so the rule
    is: once any component is a junction or symlink (its real path differs from
    its spelling), a '..' that follows it rejects. With strict=True (cd targets)
    any link at all rejects, so the tracked folder always spells its own path."""
    if path == DEVNULL:
        return path
    try:
        p = path.replace('/', '\\')
        m = re.match(r'^([A-Za-z]:)\\?(.*)$', p, re.S)
        if not m:
            return ntpath.normpath(os.path.realpath(path))
        cur = m.group(1).upper() + '\\'
        linked = False
        for comp in m.group(2).split('\\'):
            if comp in ('', '.'):
                continue
            if comp == '..':
                if linked:
                    raise Reject('.. after a junction or symlink')
                cur = ntpath.dirname(cur) or cur
                continue
            cur = ntpath.join(cur, comp)
            if os.path.lexists(cur):
                # cur holds no '..' here, so realpath only follows links.
                real = ntpath.normpath(os.path.realpath(cur))
                if ntpath.normcase(real) != ntpath.normcase(ntpath.normpath(cur)):
                    if strict:
                        raise Reject('link in a folder path')
                    linked = True
                cur = real
        return cur
    except Reject:
        raise
    except Exception:
        return ntpath.normpath(path)


def _norm_root(text):
    return _real(text.replace('/', '\\'))


PROJECT = _norm_root(
    os.environ.get('CLAUDE_PROJECT_DIR') or str(Path(__file__).resolve().parents[2])
)
_TEMP_RAW = os.environ.get('TEMP') or os.environ.get('TMP') or tempfile.gettempdir()
TEMP_DIR = _norm_root(_TEMP_RAW)
BUILD_DIR = _norm_root(ntpath.join(PROJECT, *WRITE_SAFE_SUBDIRS))


def _is_inside(path, root):
    if not path or not root:
        return False  # no scratch root (SCRATCH is None) contains nothing
    p = ntpath.normcase(path)
    r = ntpath.normcase(root).rstrip('\\')
    return p == r or p.startswith(r + '\\')


def _valid_temp(raw):
    """Round 7: TEMP/TMP is environment input, so the scratch root is accepted
    only for a folder that looks like a temp folder: its last component is Temp
    or tmp, or it lies under %LOCALAPPDATA%; it is not a filesystem root, not
    under the Windows or Program Files folders, and not an ancestor of PROJECT
    (TEMP=C:\\Users\\Kuba would otherwise make C:\\Users\\Kuba\\claude writable)."""
    if not re.match(r'^[A-Za-z]:[\\/][^\\/]', raw):
        return False  # a drive root, a relative or a UNC path
    temp = _norm_root(raw)
    last = ntpath.basename(ntpath.normcase(temp).rstrip('\\'))
    local = os.environ.get('LOCALAPPDATA')
    under_local = bool(local) and _is_inside(temp, _norm_root(local))
    if last not in ('temp', 'tmp') and not under_local:
        return False
    banned = ['C:\\Windows', 'C:\\Program Files', 'C:\\Program Files (x86)']
    for name in ('SystemRoot', 'WINDIR', 'ProgramFiles', 'ProgramFiles(x86)', 'ProgramW6432'):
        if os.environ.get(name):
            banned.append(os.environ[name])
    if any(_is_inside(temp, _norm_root(b)) for b in banned):
        return False
    return not _is_inside(PROJECT, temp)


SCRATCH = _norm_root(ntpath.join(TEMP_DIR, 'claude')) if _valid_temp(_TEMP_RAW) else None


def _read_safe(p):
    return p == DEVNULL or _is_inside(p, PROJECT) or _is_inside(p, SCRATCH)


def _write_safe(p):
    return p == DEVNULL or _is_inside(p, BUILD_DIR) or _is_inside(p, SCRATCH)


def _components(text):
    return [c for c in re.split(r'[\\/]', text) if c]


def _win_name(component):
    """Windows drops trailing dots and spaces from a name when it creates the
    file, so ".git." and ".git " are the folder .git. Compare that form."""
    return component.rstrip('. ').casefold()


def _dash_part(text):
    """True when a path component of the literal text starts with '-'."""
    return any(_win_name(c).startswith('-') for c in _components(text))


def _git_part(path):
    """True when a path has a component that is .git on disk (any case)."""
    return any(_win_name(c) == '.git' for c in _components(path))


def _in_safe_scratch_or_build(p):
    return p != DEVNULL and (_is_inside(p, BUILD_DIR) or _is_inside(p, SCRATCH))


def _test_scope_ok(p):
    """A pytest collection root may not contain or sit inside the write-safe
    places, otherwise pytest would collect files a write put there."""
    if p == DEVNULL:
        return False
    for safe in (BUILD_DIR, SCRATCH):
        if _is_inside(p, safe) or _is_inside(safe, p):
            return False
    return True


def resolve_path(text, base, strict=False):
    """Normalize a shell path to an absolute Windows path, or None if it is
    outside any form we can judge (tilde, UNC, other drive, /usr, /mnt...).
    Raises Reject for a '..' after a link (see _real)."""
    if text == DEVNULL:
        return DEVNULL
    if text == '':
        return _norm_root(base)
    if text.startswith('~') or text.startswith('\\\\') or text.startswith('//'):
        return None
    m = re.match(r'^/cygdrive/([A-Za-z])(?:/|$)(.*)$', text, re.S)
    if m:
        text = '/' + m.group(1) + '/' + m.group(2)
    m = re.match(r'^/([A-Za-z])(?:/|$)(.*)$', text, re.S)
    if m:
        p = m.group(1).upper() + ':\\' + m.group(2)
    elif text == '/tmp' or text.startswith('/tmp/'):
        p = TEMP_DIR + text[4:]
    elif text[:1] in ('/', '\\'):
        return None
    elif re.match(r'^[A-Za-z]:', text):
        if len(text) == 2 or text[2] not in '\\/':
            return None
        p = text
    else:
        p = base.rstrip('\\') + '\\' + text
    p = p.replace('/', '\\')
    if any(_reserved_name(c) for c in _components(p)):
        return None  # COM1, CON.txt, PRN... are devices on Windows, not files
    p = _real(p, strict)
    if not re.match(r'^[A-Za-z]:\\', p):
        return None
    return p


RESERVED_DEVICE_NAMES = frozenset(
    {'CON', 'PRN', 'AUX', 'NUL', 'CONIN$', 'CONOUT$'}
    | {f'{base}{d}' for base in ('COM', 'LPT') for d in '123456789¹²³'})


def _reserved_name(component):
    """Windows maps a name whose stem (text before the first dot, without
    trailing spaces or colons) is a device name to that device, in any folder."""
    return component.split('.')[0].rstrip(' :').upper() in RESERVED_DEVICE_NAMES


def _neutral(text):
    """Replace glob characters by a plain letter so '..' and separators can be
    checked textually; the glob part is always one path component."""
    return re.sub(r'[*?\[\]]', 'G', text)


def _glob_guard(text):
    if re.search(r'\[[^\]]*\.', text):
        raise Reject('glob bracket expression containing a dot')
    seen_glob = False
    for comp in re.split(r'[\\/]', text):
        if comp.startswith('.') and any(ch in comp for ch in '*?['):
            raise Reject('glob that could match . or ..')
        if seen_glob and comp == '..':
            # The expansion of the glob may be a junction; '..' after it would
            # then climb from the link target, which no lexical check can see.
            raise Reject('.. after a glob component')
        if any(ch in comp for ch in '*?['):
            seen_glob = True


def _check_path_text(text, cwd):
    p = resolve_path(text, cwd)
    if p is None or not _read_safe(p):
        raise Reject(f'path outside read-safe places: {text[:60]}')


def _check_word_path(w, cwd):
    if w.glob:
        _glob_guard(w.text)
    _check_path_text(_neutral(w.text), cwd)


def _check_short_remainder(t, cwd):
    """Check the path-like tail of a short option cluster (grep -f/etc/x).

    The attached value starts after some option letter, and this generic check
    does not know which letter takes a value, so every tail that could be the
    value is checked: each suffix that holds a separator or a drive. Slicing at
    the first separator (round 5) dropped a leading 'J' of 'fJ../..' and let
    the junction walk escape."""
    rem = t[1:]
    for k in range(len(rem)):
        tail = rem[k:]
        if re.search(r'[\\/]', tail) or re.match(r'[A-Za-z]:', tail):
            _check_path_text(_neutral(tail), cwd)


def _pattern_path(text, cwd):
    """Absolute native pattern for a glob word, or None if it is outside."""
    m = re.match(r'^/([A-Za-z])(?:/|$)(.*)$', text, re.S)
    if m:
        return m.group(1).upper() + ':\\' + m.group(2).replace('/', '\\')
    if text[:1] in ('/', '\\') or text.startswith('~'):
        return None
    if re.match(r'^[A-Za-z]:', text):
        return text.replace('/', '\\')
    return cwd.rstrip('\\') + '\\' + text.replace('/', '\\')


MAX_GLOB_MATCHES = 5000
_GLOB_CACHE = {}  # native pattern -> matches, for this classify() call


def _over_budget(start):
    return _WALK_TIME[0] + (time.monotonic() - start) > WALK_TIME_BUDGET


def _glob_expand(pat, start):
    """Expand a native glob pattern level by level (round 7). Unlike glob.glob
    it stops at MAX_GLOB_MATCHES entries and at the shared walk time budget, so a
    pattern such as */*/*/*/*/*/* cannot run for seconds. Both limits reject: an
    incomplete expansion never allows. Wildcards skip names that start with a dot
    unless the component does, as the shell and glob.glob do."""
    if pat in _GLOB_CACHE:
        return _GLOB_CACHE[pat]
    drive, rest = ntpath.splitdrive(pat)
    only_dirs = rest.endswith('\\')
    comps = [c for c in rest.split('\\') if c]
    cur = [drive + '\\']
    seen = 0
    for k, comp in enumerate(comps):
        last = k == len(comps) - 1
        nxt = []
        if not re.search(r'[*?\[]', comp):
            for d in cur:
                cand = ntpath.join(d, comp)
                if os.path.lexists(cand):
                    nxt.append(cand)
        else:
            for d in cur:
                if _over_budget(start):
                    raise Reject('walk budget exceeded')
                try:
                    it = os.scandir(d)
                except OSError:
                    continue
                with it:
                    for entry in it:
                        seen += 1
                        if seen % 256 == 0 and _over_budget(start):
                            raise Reject('walk budget exceeded')
                        name = entry.name
                        if name.startswith('.') and not comp.startswith('.'):
                            continue
                        if not fnmatchcase(ntpath.normcase(name), ntpath.normcase(comp)):
                            continue
                        if not last or only_dirs:
                            try:
                                if not entry.is_dir():
                                    continue
                            except OSError:
                                continue
                        nxt.append(ntpath.join(d, name))
                        if len(nxt) > MAX_GLOB_MATCHES:
                            raise Reject('glob matches too many files')
        cur = nxt
        if not cur:
            break
    if len(cur) > MAX_GLOB_MATCHES:
        raise Reject('glob matches too many files')
    _GLOB_CACHE[pat] = cur
    return cur


def _glob_check(w, cwd):
    """Judge an unquoted glob word by what it can expand to at run time."""
    if not w.glob:
        return
    t = w.text
    if _dash_part(t):
        # --include=*.php: the option name is literal and the glob only forms a
        # name pattern for that option, so the expansion keeps the same option.
        name, eq, _rest = t.partition('=')
        if eq and name in GLOB_PATTERN_OPTS and not re.search(r'[*?\[]', name):
            return
        raise Reject('glob word with a component starting with -')
    _glob_guard(t)
    _check_path_text(_neutral(t), cwd)
    pat = _pattern_path(t, cwd)
    if pat is None:
        raise Reject('glob outside read-safe places')
    start = time.monotonic()
    try:
        matches = _glob_expand(pat, start)
        for k, m in enumerate(matches):
            if k % 64 == 63 and _over_budget(start):
                raise Reject('walk budget exceeded')
            r = _real(ntpath.normpath(m))
            if not _read_safe(r) or _dash_part(r):
                raise Reject('glob match outside read-safe places or a dash name')
    finally:
        _WALK_TIME[0] += time.monotonic() - start


# ---------------------------------------------------------------- tokenizer
class _Word:
    __slots__ = ('text', 'unq', 'glob', 'quoted', 'pwd', 'status', 'vars')

    def __init__(self, text, unq, glob, quoted, pwd, status=False, vars=()):
        self.vars = vars  # names of assigned-variable placeholders in text (round 7)
        self.text = text
        self.unq = unq
        self.glob = glob
        self.quoted = quoted
        self.pwd = pwd
        self.status = status  # the word holds $? (a number whose value is unknown)


class _Redir:
    __slots__ = ('op', 'fd', 'target')

    def __init__(self, op, fd, target):
        self.op = op
        self.fd = fd
        self.target = target


VAR_NAME_RE = re.compile(r'[A-Za-z_][A-Za-z0-9_]*')
PLACEHOLDER_RE = re.compile('\x02([A-Za-z_][A-Za-z0-9_]*)\x03')


def _var_value(name, pwd_value):
    if name == 'PWD':
        return pwd_value
    if name == '?':
        return '0'  # stands for the exit status; see the status flag on _Word
    return os.environ.get(name) or TEMP_DIR


def _lex(text, pwd_value):
    toks = []
    st = {'text': [], 'unq': [], 'glob': False, 'quoted': False, 'pwd': False,
          'status': False, 'open': False, 'vars': [], 'paren': 0}

    def reset():
        st.update(text=[], unq=[], glob=False, quoted=False, pwd=False, status=False,
                  open=False, vars=[], paren=0)

    def emit():
        if st['open']:
            toks.append(('W', _Word(''.join(st['text']), ''.join(st['unq']),
                                    st['glob'], st['quoted'], st['pwd'], st['status'],
                                    tuple(st['vars']))))
        reset()

    def put(chars, quoted=False, glob=False):
        st['open'] = True
        st['text'].append(chars)
        st['unq'].append('\x01' * len(chars) if quoted else chars)
        st['quoted'] = st['quoted'] or quoted
        st['glob'] = st['glob'] or glob

    def dollar(j):
        nxt = text[j + 1:j + 2]
        if nxt in ('(', '[', "'", '"', '`') and nxt:
            raise Reject('command substitution, arithmetic or quoting after $')
        if nxt == '{':
            k = text.find('}', j + 2)
            if k < 0:
                raise Reject('unterminated ${')
            name = text[j + 2:k]
            if name not in ALLOWED_VARS and not VAR_NAME_RE.fullmatch(name):
                raise Reject(f'variable not allowed: {name}')
            return k + 1, name
        m = re.match(r'[A-Za-z_][A-Za-z0-9_]*', text[j + 1:])
        if m:
            name = m.group(0)
            return j + 1 + len(name), name
        if nxt == '?':
            # $? expands to a number, so it is the one special parameter allowed;
            # the word is flagged so that no path or redirect target holds it.
            return j + 2, '?'
        if nxt and (nxt.isdigit() or nxt in '@*#-!$'):
            raise Reject('shell special parameter')
        return j + 1, None

    def var_text(name):
        """Text of $name. A name outside ALLOWED_VARS becomes a placeholder that
        _classify swaps for the value of a standalone assignment (round 7), or
        rejects when the name was not assigned earlier in the command."""
        if name in ALLOWED_VARS or name == '?':
            if name == 'PWD':
                st['pwd'] = True
            if name == '?':
                st['status'] = True
            return _var_value(name, pwd_value)
        st['vars'].append(name)
        return '\x02' + name + '\x03'

    def var_put(name, quoted):
        put(var_text(name), quoted=quoted)

    i, n = 0, len(text)
    while i < n:
        c = text[i]
        if c in ' \t':
            emit()
            i += 1
        elif c in '\n;':
            emit()
            toks.append(('OP', c))
            i += 1
        elif c == '|':
            emit()
            if text[i + 1:i + 2] == '|':
                toks.append(('OP', '||'))
                i += 2
            else:
                toks.append(('OP', '|'))
                i += 1
        elif c == '&':
            nxt = text[i + 1:i + 2]
            if nxt == '&':
                emit()
                toks.append(('OP', '&&'))
                i += 2
            elif nxt == '>':
                emit()
                if text[i + 2:i + 3] == '>':
                    toks.append(('R', '&>>', None))
                    i += 3
                else:
                    toks.append(('R', '&>', None))
                    i += 2
            else:
                raise Reject('background or lone & operator')
        elif c in '<>':
            fd = None
            if st['open'] and not st['quoted'] and ''.join(st['text']).isdigit():
                fd = ''.join(st['text'])
                reset()
            else:
                emit()
            if c == '<':
                if text[i + 1:i + 2] in ('<', '>', '&', '('):
                    raise Reject('heredoc, here-string, process or fd redirection')
                toks.append(('R', '<', fd))
                i += 1
            else:
                nxt = text[i + 1:i + 2]
                if nxt == '>':
                    if text[i + 2:i + 3] in ('&', '|', '('):
                        raise Reject('unsupported redirection form')
                    toks.append(('R', '>>', fd))
                    i += 2
                elif nxt == '&':
                    toks.append(('R', '>&', fd))
                    i += 2
                elif nxt in ('|', '('):
                    raise Reject('noclobber or process substitution')
                else:
                    toks.append(('R', '>', fd))
                    i += 1
        elif c in '()':
            # Round 7: git's --format=%(refname) holds parentheses. Only inside a
            # single --format= word, balanced; _check_segment then requires that
            # the command is git for-each-ref, log, branch or tag.
            if st['open'] and ''.join(st['text']).startswith('--format=')                     and (c == '(' or st['paren'] > 0):
                st['paren'] += 1 if c == '(' else -1
                put(c)
                i += 1
            else:
                raise Reject('grouping parentheses')
        elif c == '`':
            raise Reject('backtick command substitution')
        elif c == '#' and not st['open']:
            raise Reject('comment')
        elif c == '\\':
            if i + 1 >= n:
                raise Reject('trailing backslash')
            nxt = text[i + 1]
            if nxt in '\r\n':
                raise Reject('backslash line continuation')
            put(nxt, quoted=True)
            i += 2
        elif c == "'":
            j = text.find("'", i + 1)
            if j < 0:
                raise Reject('unbalanced single quote')
            body = text[i + 1:j]
            if '\n' in body:
                raise Reject('newline inside quotes')
            put(body, quoted=True)
            i = j + 1
        elif c == '"':
            j = i + 1
            buf = []
            while True:
                if j >= n:
                    raise Reject('unbalanced double quote')
                d = text[j]
                if d == '"':
                    break
                if d == '\\':
                    if j + 1 >= n:
                        raise Reject('unbalanced double quote')
                    nxt = text[j + 1]
                    if nxt in '"\\$`':
                        buf.append(nxt)
                        j += 2
                    elif nxt in '\r\n':
                        raise Reject('backslash newline inside quotes')
                    else:
                        buf.append('\\')
                        j += 1
                elif d == '`':
                    raise Reject('backtick command substitution')
                elif d in '\r\n':
                    raise Reject('newline inside quotes')
                elif d == '$':
                    nj, name = dollar(j)
                    if name is None:
                        buf.append('$')
                    else:
                        buf.append(var_text(name))
                    j = nj
                else:
                    buf.append(d)
                    j += 1
            put(''.join(buf), quoted=True)
            i = j + 1
        elif c == '$':
            nj, name = dollar(i)
            if name is None:
                put('$')
            else:
                var_put(name, True)
            i = nj
        elif c == '~' and not st['open']:
            raise Reject('tilde expansion')
        elif c == '[':
            m = GLOB_BRACKET_RE.match(text, i)
            if m:
                if not GLOB_BRACKET_BODY_OK.fullmatch(m.group(1)):
                    raise Reject('bracket expression not allowed (classes, ^, dots)')
                put(m.group(0), glob=True)
                i = m.end()
            else:
                put(c, glob=True)
                i += 1
        elif c in '*?':
            put(c, glob=True)
            i += 1
        else:
            put(c)
            i += 1
    emit()
    return toks


def _segments(toks):
    """Split tokens into simple commands. Returns (segs, ops) where ops[k] is
    the operator that follows segs[k] (';', newline, '&&', '||', '|') or None."""
    segs, ops, cur, last_op, i = [], [], [], None, 0
    while i < len(toks):
        kind = toks[i][0]
        if kind == 'W':
            cur.append(toks[i][1])
            last_op = None
        elif kind == 'R':
            if i + 1 >= len(toks) or toks[i + 1][0] != 'W':
                raise Reject('redirection without a target')
            cur.append(_Redir(toks[i][1], toks[i][2], toks[i + 1][1]))
            i += 1
            last_op = None
        else:
            if not cur:
                raise Reject('empty command before an operator')
            segs.append(cur)
            ops.append(toks[i][1])
            cur = []
            last_op = toks[i][1]
        i += 1
    if cur:
        segs.append(cur)
        ops.append(None)
    elif last_op not in (None, ';', '\n'):
        raise Reject('dangling operator')
    if not segs:
        raise Reject('empty command')
    return segs, ops


# ---------------------------------------------------------------- redirects
def _write_target_word(w, cwd):
    """The single write rule for redirects, touch and mkdir: the target must
    resolve inside PROJECT/build or the scratch root, be a literal (no glob),
    have no component starting with '-', and not be inside a .git folder."""
    if w.glob:
        raise Reject('glob in write target')
    if _dash_part(w.text):
        raise Reject('write target with a component starting with -')
    p = resolve_path(w.text, cwd)
    if p is None or not _write_safe(p) or p == DEVNULL:
        raise Reject(f'write target not write-safe: {w.text[:60]}')
    if _git_part(p):
        raise Reject('write target inside .git')
    _log_guard(p)


def _log_guard(p):
    """Round 7: the decision log build/auto-allow.log (and its rotated
    auto-allow.log.N copies) is the hook's own record, so no write may name it:
    the first component under build/ is compared, any case, without the
    trailing dots, spaces and ':stream' that Windows drops or splits off."""
    if not _is_inside(p, BUILD_DIR) or ntpath.normcase(p).rstrip('\\') == ntpath.normcase(BUILD_DIR).rstrip('\\'):
        return
    rel = _components(p[len(BUILD_DIR.rstrip('\\')):])
    if rel:
        first = _win_name(rel[0].split(':')[0])
        if first == LOG_NAME or first.startswith(LOG_NAME + '.'):
            raise Reject('write target is the decision log')


def _check_write_target(w, cwd):
    if not w.glob and w.text == DEVNULL:
        return
    _write_target_word(w, cwd)


def _check_redirect(r, cwd):
    op, fd, tgt = r.op, r.fd, r.target
    if op == '<':
        if fd not in (None, '0'):
            raise Reject('input redirection on another fd')
        if tgt.text == DEVNULL:
            return
        _glob_check(tgt, cwd)
        _check_word_path(tgt, cwd)
    elif op in ('>', '>>'):
        if fd not in (None, '1', '2'):
            raise Reject('output redirection on another fd')
        _check_write_target(tgt, cwd)
    elif op == '>&':
        if tgt.text in ('1', '2'):
            if fd not in (None, '1', '2'):
                raise Reject('fd duplication on another fd')
            return
        if tgt.text == '-':
            raise Reject('closing a file descriptor is not allowed')
        # N>&FILE and >&FILE both send the stream to FILE: a file write.
        if fd not in (None, '1', '2'):
            raise Reject('output redirection on another fd')
        _check_write_target(tgt, cwd)
    elif op in ('&>', '&>>'):
        if fd is not None:
            raise Reject('fd-prefixed file redirection')
        _check_write_target(tgt, cwd)
    else:
        raise Reject(f'redirection not allowed: {op}')


# ---------------------------------------------------------------- generic args
def _ident(path):
    """Identity of a resolved path as Windows sees it: each component folded to
    lower case, without trailing dots and spaces (x.txt. is x.txt)."""
    if path is None:
        return None
    return tuple(_win_name(c) for c in _components(path))


def _value_suffixes(value):
    """The option value and every text after a ':' or '=' in it. git and pytest
    accept name:path and name=path forms, so each part must be judged."""
    parts = [value]
    for k, ch in enumerate(value):
        if ch in ':=' and value[k + 1:]:
            parts.append(value[k + 1:])
    return parts


def _long_conflicts(name, dangerous):
    """A long option conflicts with a dangerous one when it abbreviates it or
    extends it with a dash (--cov-report extends --cov, --pretty does not extend
    --pre). Names shorter than 3 never match."""
    return len(name) >= 3 and any(d.startswith(name) or name.startswith(d + '-') for d in dangerous)


def _plain_word(text):
    return _Word(text, text, False, False, False)


def _reject_args(args, exact=(), prefix=(), short=''):
    for w in args:
        t = w.text
        if t in exact or any(t.startswith(p) for p in prefix):
            raise Reject(f'option not allowed: {t}')
        if short and t.startswith('-') and not t.startswith('--') \
                and any(ch in t[1:] for ch in short):
            raise Reject(f'option not allowed: {t}')


def _check_args(args, cwd, skip=frozenset(), nonpath=frozenset()):
    """Every non-option argument (and every --opt=VALUE and option-attached
    path) must be read-safe, resolved against the given directory. A short
    option whose letter is in nonpath (cut -d/) takes a value that is not a path."""
    skip = set(skip)
    for idx, w in enumerate(args):
        t = w.text
        if t == '--':
            break
        if t.startswith('-') and not t.startswith('--') and len(t) > 1 and t[1] in nonpath:
            skip.add(idx)
            if len(t) == 2 and idx + 1 < len(args):
                skip.add(idx + 1)
    after_dd = False
    for idx, w in enumerate(args):
        if idx in skip:
            continue
        t = w.text
        if after_dd:
            _check_word_path(w, cwd)
            continue
        if t == '--':
            after_dd = True
            continue
        if t == '-':
            continue
        if t.startswith('--'):
            if '=' in t:
                for part in _value_suffixes(t.split('=', 1)[1]):
                    if part:
                        _check_path_text(_neutral(part), cwd)
            continue
        if t.startswith('-') and len(t) > 1:
            _check_short_remainder(t, cwd)
            continue
        _check_word_path(w, cwd)


def _check_head(head):
    t = head.text
    if head.glob and t != '[':
        raise Reject('glob in command name')
    if not t:
        raise Reject('empty command name')
    if re.match(r'[A-Za-z_][A-Za-z0-9_]*=', t):
        raise Reject('leading NAME=value assignment')
    if t in SHELL_KEYWORDS:
        raise Reject(f'shell keyword or grouping: {t}')


def _is_path_head(name):
    return ('/' in name or '\\' in name or name.startswith('.')
            or re.match(r'^[A-Za-z]:', name) is not None)


def _cd(args, cwd):
    if len(args) != 1:
        raise Reject('cd needs exactly one directory')
    w = args[0]
    t = w.text
    if w.glob or not t or t.startswith('-') or t.startswith('~'):
        raise Reject('cd form not allowed')
    # strict: no link anywhere in the target, so the tracked folder is the one
    # the shell reaches with a lexical cd (a junction would make the two differ).
    p = resolve_path(t, cwd, strict=True)
    if p is None or p == DEVNULL or not _read_safe(p):
        raise Reject('cd outside read-safe places')
    if not os.path.isdir(p):
        raise Reject('cd target is not an existing directory')
    return p


def _scan_short(args, ok, value_letters, deny=frozenset()):
    """Walk the short option clusters. A value letter ends its cluster and
    takes the rest of it (or the next word). Returns the indexes of separate
    value words. ok=None accepts any letter that is not denied."""
    skip, i, n = set(), 0, len(args)
    while i < n:
        t = args[i].text
        if t == '--':
            break
        if t.startswith('-') and not t.startswith('--') and len(t) > 1:
            for k in range(1, len(t)):
                ch = t[k]
                if ch in deny:
                    raise Reject(f'option not allowed: -{ch}')
                if ch in value_letters:
                    if k + 1 == len(t):
                        if i + 1 >= n:
                            raise Reject(f'option -{ch} without value')
                        skip.add(i + 1)
                        i += 1
                    break
                if ok is not None and ch not in ok:
                    raise Reject(f'option not allowed: -{ch}')
        i += 1
    return skip


def _long_allow(args, allowed):
    """Exact long-option allow list: abbreviations are not on it."""
    for w in args:
        t = w.text
        if t.startswith('--') and t != '--':
            name = t.split('=', 1)[0]
            if name not in allowed:
                raise Reject(f'option not allowed: {name}')


def _long_prefix_reject(args, dangerous):
    """Reject a long option that abbreviates or extends a dangerous one, because
    argparse, getopt and git's parser accept unique abbreviations."""
    for w in args:
        t = w.text
        if t.startswith('--'):
            name = t.split('=', 1)[0]
            if _long_conflicts(name, dangerous):
                raise Reject(f'option not allowed (abbreviation or extension): {name}')


# ---------------------------------------------------------------- recursive walks
def _is_link(entry):
    """True for a symbolic link or a Windows junction (a reparse point)."""
    try:
        if entry.is_symlink():
            return True
        st = entry.stat(follow_symlinks=False)
        return bool(getattr(st, 'st_file_attributes', 0) & 0x400)
    except OSError:
        return True


def _walk_check(roots):
    """Recursive readers (grep -r, find, rg, tree, du, ls -R) follow what they
    find below a root, including links. Every link or junction below the roots
    must resolve inside a read-safe place, and the walk is bounded: a tree too
    large to check is refused rather than trusted."""
    # A folder already checked in this classify() call (or below one) is not walked
    # again: 'grep -rn x . ; find .' pays for the walk once.
    todo = [r for r in roots
            if not any(_ident(r)[:len(w)] == w for w in _WALKED)]
    if not todo:
        return
    start = time.monotonic()
    try:
        _walk_check_inner(todo, start)
        _WALKED.extend(_ident(r) for r in todo)
    finally:
        _WALK_TIME[0] += time.monotonic() - start


def _walk_check_inner(roots, start):
    seen, stack, budget = set(), [], WALK_MAX_ENTRIES
    for r in roots:
        key = _ident(r)
        if key not in seen:
            seen.add(key)
            stack.append(r)
    tick = 0
    while stack:
        here = stack.pop()
        try:
            it = os.scandir(here)
        except OSError:
            continue
        with it:
            for entry in it:
                budget -= 1
                if budget < 0:
                    raise Reject('tree too large to check its links')
                tick += 1
                if tick % 32 == 0 and _WALK_TIME[0] + (time.monotonic() - start) > WALK_TIME_BUDGET:
                    raise Reject('walk budget exceeded')
                if _is_link(entry):
                    target = _real(entry.path)
                    if not _read_safe(target):
                        raise Reject('link below a searched folder points outside')
                    key = _ident(target)
                    if key not in seen and os.path.isdir(target):
                        seen.add(key)
                        stack.append(target)
                    continue
                try:
                    if entry.is_dir(follow_symlinks=False):
                        stack.append(entry.path)
                except OSError:
                    pass


def _walk_guard(words, cwd, default_root):
    """Collect the folders among the path words (globs expanded) and walk them.
    With no folder operand, a recursive tool searches the working folder."""
    roots = []
    for w in words:
        if w.glob:
            pat = _pattern_path(w.text, cwd)
            if pat is None:
                continue
            start = time.monotonic()
            try:
                found = _glob_expand(pat, start)
                roots.extend(_real(m) for m in found if os.path.isdir(m))
            finally:
                _WALK_TIME[0] += time.monotonic() - start
        elif not (w.text.startswith('-') and w.text != '-'):
            p = resolve_path(w.text, cwd)
            if p is not None and p != DEVNULL and os.path.isdir(p):
                roots.append(p)
    if not roots and default_root:
        roots.append(cwd)
    _walk_check(roots)


def _grep_like(args, cwd, name):
    """grep, egrep, fgrep, zgrep, rg and git grep. The first operand is the search
    pattern unless -e or -f supplies the patterns, so it is not checked as a path.
    An option whose meaning is unknown turns that rule off (every operand is then
    checked), because a shifted operand could hide a file name. A value word of
    an option is never the pattern. rg always walks; grep walks with -r or -R."""
    short_value, short_novalue = GREP_SHORT[name]
    skip, pos = set(), []
    have_e = ambiguous = False
    files_mode = name == 'rg' and any(w.text == '--files' for w in args)
    # -d recurse may be attached (-drecurse): any word that names recursion counts.
    walk = name == 'rg' or any('recurs' in w.text for w in args)
    i, n, after_dd = 0, len(args), False
    while i < n:
        t = args[i].text
        if after_dd:
            pos.append(i)
        elif t == '--':
            after_dd = True
        elif t.startswith('--'):
            nm, eq, _val = t.partition('=')
            if nm in GREP_LONG_PATTERN:
                have_e = True
                if eq:
                    skip.add(i)
                elif i + 1 < n:
                    skip.add(i + 1)
                    i += 1
            elif nm in GREP_LONG_VALUE:
                if nm == '--file':
                    # The patterns come from a file, so no operand is a pattern.
                    have_e = True
                if not eq and i + 1 < n:
                    i += 1
            elif nm in GREP_LONG_NOVALUE:
                if nm in ('--recursive', '--dereference-recursive') and name == 'grep':
                    walk = True
            else:
                ambiguous = True
        elif t.startswith('-') and len(t) > 1:
            for k in range(1, len(t)):
                ch = t[k]
                last = k + 1 == len(t)
                if ch == 'e':
                    have_e = True
                    if not last:
                        skip.add(i)
                    elif i + 1 < n:
                        skip.add(i + 1)
                        i += 1
                    break
                if ch == 'f':
                    # -f FILE: the patterns come from a file, so every operand is
                    # a file. An attached value is checked here, as a whole path.
                    have_e = True
                    if not last:
                        _check_path_text(_neutral(t[k + 1:]), cwd)
                        skip.add(i)
                    break
                if ch in short_value:
                    if last and i + 1 < n:
                        i += 1
                    break
                if ch in short_novalue:
                    if name == 'grep' and ch in 'rR':
                        walk = True
                    continue
                ambiguous = True
                break
        else:
            pos.append(i)
        i += 1
    if not have_e and not ambiguous and not files_mode and pos:
        skip.add(pos[0])
    _check_args(args, cwd, skip=frozenset(skip))
    if walk and name != 'git':
        remaining = [args[j] for j in pos if j not in skip]
        _walk_guard(remaining, cwd, default_root=not remaining)


def _awk_structure_ok(code):
    """No loops and no user functions (an unbounded loop prints forever or never
    ends), every for has a condition and a step (or is a for-in), at most
    AWK_MAX_PRINTS print statements, and no printf width of five or more digits."""
    if re.search(r'\b(?:while|do|function|func)\b', code):
        raise Reject('awk loop or user function (unbounded output or recursion)')
    for m in re.finditer(r'\bfor\b', code):
        tail = code[m.end():]
        head = re.match(r'\s*\(([^;()]*);([^;()]*);([^;()]*)\)', tail)
        if head:
            if not head.group(2).strip() or not head.group(3).strip():
                raise Reject('awk for loop without a condition or step')
            if not re.search(r'[A-Za-z_$]', head.group(2)):
                raise Reject('awk for loop with a constant condition (never ends)')
            if re.search(r'[0-9]{5}', head.group(0)) or re.search(r'[0-9.][eE][+-]?[0-9]', head.group(0)):
                raise Reject('awk for loop with a huge bound')
            # The step must move a variable of the condition towards its bound.
            cond, step = head.group(2), head.group(3)
            if not set(re.findall(r'[A-Za-z_]\w*', cond)) & set(re.findall(r'[A-Za-z_]\w*', step)):
                raise Reject('awk for loop step does not touch its condition')
            if '<' in cond and not re.search(r'\+\+|\+=', step):
                raise Reject('awk for loop counts the wrong way')
            if '>' in cond and not re.search(r'--|-=', step):
                raise Reject('awk for loop counts the wrong way')
        elif not re.match(r'\s*\(\s*[A-Za-z_]\w*\s+in\s+[A-Za-z_]\w*\s*\)', tail):
            raise Reject('awk for loop form not allowed')
    if len(AWK_PRINT_RE.findall(code)) > AWK_MAX_PRINTS:
        raise Reject('awk has too many print statements (output size)')
    if re.search(r'%[-+ #0]*[0-9]{5}', code):
        raise Reject('awk printf width of five or more digits (output size)')


AWK_BUILTIN_NAMES = frozenset({
    'NR', 'NF', 'FNR', 'FS', 'OFS', 'ORS', 'RS', 'FILENAME', 'RSTART', 'RLENGTH',
    'SUBSEP', 'CONVFMT', 'OFMT', 'BEGIN', 'END', 'in', 'if', 'else', 'for', 'print',
    'printf', 'next', 'exit', 'delete',
})


def _awk_format_ok(prog):
    """Round 7: a printf format must be a string literal (a built format could
    carry any width), and no literal may hold a '*' width or a width or precision
    of five or more digits. This looks at the program with its strings intact:
    the checks in _awk_structure_ok see only the stripped code."""
    for s in AWK_STRING_RE.findall(prog):
        if re.search(r'%[-+ #0-9.]*\*', s) or re.search(r'%[-+ #0-9.]*[0-9]{5}', s):
            raise Reject('awk printf width or precision that is a star or has five digits')
    for m in re.finditer(r'\bs?printf\b[ \t]*\(?[ \t]*', prog):
        if prog[m.end():m.end() + 1] != '"':
            raise Reject('awk printf format that is not a string literal')


def _awk_growth_ok(code):
    """Round 7: a variable assigned from itself twice (s = s s, s = s s $0,
    s = sprintf("%s%s", s, s)) doubles on every record, and so does a pure
    concatenation of two variables that includes the assigned one (a = a b)."""
    for m in re.finditer(r'\b([A-Za-z_]\w*)[ \t]*=(?!=)([^;}\n]*)', code):
        lhs, rhs = m.group(1), m.group(2)
        ids = [x for x in re.findall(r'[A-Za-z_]\w*(?![\w(])', rhs) if x not in AWK_BUILTIN_NAMES]
        if ids.count(lhs) >= 2:
            raise Reject('awk variable assigned from itself more than once (doubles)')
        if lhs in ids and len(ids) >= 2 and not re.search(r'[-+*/%^(?:<>!]', rhs):
            raise Reject('awk concatenation of variables that can double')


def _awk_output_redirect(prog):
    """A '>' is output redirection only inside a print or printf statement and
    outside parentheses. Anywhere else it is a comparison (NR>1). '>>' is refused
    outright. String literals are removed first so their text cannot hide a '>'."""
    if '"' in prog and '/' in prog:
        # A regex literal such as /"/ makes the string stripper pair quotes that
        # awk does not pair, which hides a print > "file" (round 4). Refuse.
        raise Reject('awk program mixes quotes and slashes')
    _awk_format_ok(prog)
    code = AWK_STRING_RE.sub('""', prog) if '"' in prog else prog
    if '>>' in code:
        raise Reject('awk >> operator')
    _awk_structure_ok(code)
    _awk_growth_ok(code)
    for m in AWK_PRINT_RE.finditer(code):
        depth = 0
        for ch in code[m.end():]:
            if ch == '(':
                depth += 1
            elif ch == ')':
                depth -= 1
            elif depth <= 0 and ch in ';}\n':
                break
            elif depth <= 0 and ch == '>':
                raise Reject('awk output redirection inside print or printf')


def _printf(args):
    """printf -v assigns a shell variable, and printf -v PWD changes what $PWD
    means, so printf takes no options. A field width or count of five or more
    digits can print gigabytes, so such numbers are refused."""
    if args and args[0].text.startswith('-'):
        raise Reject('printf options (printf -v assigns shell variables)')
    if any(re.search(r'[0-9]{5}', w.text) for w in args):
        raise Reject('printf number of five or more digits (output size)')


PS_ARG_RE = re.compile(r'--?[A-Za-z][A-Za-z-]*|[A-Za-z]+|[0-9,]+|[A-Za-z][A-Za-z0-9,=_]*')


def _ps(args):
    """ps lists processes and writes nothing. Its words are options and column or
    process-id lists (aux, -ef, -o pid,cmd), never paths, so they are not
    path-checked; anything with a separator, drive, '@' or '~' still rejects."""
    for w in args:
        if w.glob or not PS_ARG_RE.fullmatch(w.text):
            raise Reject(f'ps argument not allowed: {w.text[:40]}')


GRAPHIFY_SUBS = frozenset({'query', 'explain', 'path', 'update', 'build', 'report'})
# Round 7: option names that contain one of these can name an output, a provider
# or a service, or install something; they reject for every graphify subcommand.
GRAPHIFY_BAD_OPT_PARTS = ('out', 'output', 'dir', 'backend', 'postgres', 'workspace',
                          'provider', 'install', 'hook', 'memory', 'label', 'merge')
OPENSPEC_SUBS = frozenset({'list', 'show', 'status', 'instructions', 'validate', 'context',
                           'new', 'archive', 'sync', 'update', 'schemas', 'doctor'})
OPENSPEC_BAD_OPTS = frozenset({'--force', '--code-workspace'})


def _tool_args_ok(args):
    """Shared by graphify and openspec: no URL or DSN value anywhere."""
    for w in args:
        low = w.text.lower()
        if '://' in low or 'host=' in low:
            raise Reject('URL or connection string argument')


def _graphify(args, cwd):
    """Round 7: an ALLOW list of subcommands (the rest of graphify installs hooks
    and skills into the home folder, calls models and databases, or writes where
    the caller names). Only the read forms and the two project builds run."""
    _tool_args_ok(args)
    texts = [w.text for w in args]
    if texts in (['--help'], ['-h'], ['--version']):
        return
    if not texts or texts[0] not in GRAPHIFY_SUBS:
        raise Reject(f'graphify subcommand not allowed: {texts[0] if texts else ""}')
    sub, rest = texts[0], args[1:]
    if [w.text for w in rest] == ['--help']:
        return
    for w in rest:
        t = w.text
        if t.startswith('-') and t != '-':
            name = t.split('=', 1)[0].lower()
            if any(part in name for part in GRAPHIFY_BAD_OPT_PARTS):
                raise Reject(f'graphify option not allowed: {name}')
    _check_args(rest, cwd)
    if sub in ('update', 'build'):
        for w in rest:
            if not w.text.startswith('-') or w.text == '-':
                p = resolve_path(w.text, cwd)
                if p is None or not _is_inside(p, PROJECT):
                    raise Reject('graphify update/build path outside the project')


def _openspec(args, cwd):
    """Round 7: an ALLOW list of openspec subcommands. config, init, view, store
    mutations, workset, schema fork and the rest write outside the project or open
    editors; --force and --code-workspace reject wherever they appear."""
    _tool_args_ok(args)
    i = 0
    while i < len(args) and args[i].text == '--no-color':
        i += 1
    texts = [w.text for w in args[i:]]
    if texts in (['--help'], ['-h'], ['--version']):
        return
    if not texts:
        raise Reject('openspec without subcommand')
    sub = texts[0]
    if any(t.split('=', 1)[0] in OPENSPEC_BAD_OPTS for t in texts):
        raise Reject('openspec --force or --code-workspace')
    if sub == 'new':
        if texts[1:2] != ['change']:
            raise Reject('openspec new only for a change')
    elif sub == 'schema':
        if texts[1:2] not in (['list'], ['show']):
            raise Reject('openspec schema form not allowed')
    elif sub == 'store':
        if texts[1:2] not in (['list'], ['show']):
            raise Reject('openspec store form not allowed')
    elif sub not in OPENSPEC_SUBS:
        raise Reject(f'openspec subcommand not allowed: {sub}')
    _check_args(args[i:], cwd)


def _tail(args, cwd):
    """Round 7: tail -f / -F / --follow (any abbreviation, any cluster) never
    returns."""
    for w in args:
        t = w.text
        if t == '--':
            break
        if t.startswith('--'):
            nm = t.split('=', 1)[0][2:]
            if nm and 'follow'.startswith(nm):
                raise Reject('tail --follow never returns')
        elif t.startswith('-') and re.search(r'[fF]', t[1:]):
            raise Reject('tail -f / -F never returns')
    _check_args(args, cwd)


def _test_cmd(args, cwd):
    """Round 7: bash evaluates the operand of test -v NAME[...] arithmetically
    and runs a $(...) inside it, and [ x -eq y ] style operands can do the same,
    so -v, -R and any operand holding [, $( or a backtick reject."""
    for w in args:
        t = w.text
        if t in ('-v', '-R') or '[' in t or '$(' in t or '`' in t:
            raise Reject('test/[ with -v, -R or a subscript or substitution in an operand')
    _check_args(args, cwd)


def _windows_search(name, args, cwd):
    """findstr and where take /X and /c:TEXT switches. A switch is not a path
    (resolve_path would read /i as the drive I:), so it is skipped; findstr /s and
    where /r walk folders and get the link check."""
    rest, recursive = [], False
    for w in args:
        t = w.text
        if re.fullmatch(r'/[A-Za-z]{1,2}', t):
            if (name == 'findstr' and t.lower() == '/s') or (name == 'where' and t.lower() == '/r'):
                recursive = True
            continue
        if t[:3].lower() == '/c:':
            continue
        rest.append(w)
    _check_args(rest, cwd)
    if recursive:
        _walk_guard(rest, cwd, default_root=True)


# ---------------------------------------------------------------- sed
def _sed_until(s, j, delim):
    n = len(s)
    while j < n:
        c = s[j]
        if c == '\\':
            if j + 1 >= n or s[j + 1] == '\n':
                raise Reject('bad sed escape')
            j += 2
            continue
        if c == '\n':
            raise Reject('newline in sed part')
        if c == delim:
            return j + 1
        j += 1
    raise Reject('unterminated sed part')


def _sed_address(s, i):
    n = len(s)
    if i < n and s[i].isdigit():
        j = i
        while j < n and s[j].isdigit():
            j += 1
        if j < n and s[j] == '~':
            k = j + 1
            while k < n and s[k].isdigit():
                k += 1
            if k == j + 1:
                raise Reject('bad sed step address')
            return k
        return j
    if i < n and s[i] == '$':
        return i + 1
    if i < n and s[i] == '/':
        return _sed_until(s, i + 1, '/')
    return i


def _sed_address2(s, i):
    n = len(s)
    if i < n and s[i] == '+':
        j = i + 1
        while j < n and s[j].isdigit():
            j += 1
        if j == i + 1:
            raise Reject('bad sed range')
        return j
    j = _sed_address(s, i)
    if j == i:
        raise Reject('bad sed range end')
    return j


def _sed_subst(s, i):
    if i >= len(s):
        raise Reject('incomplete sed s command')
    d = s[i]
    if d in '\\\n;' or d.isalnum() or d in ' \t':
        raise Reject('bad sed delimiter')
    j = _sed_until(s, i + 1, d)
    start = j
    j = _sed_until(s, j, d)
    if j - start > SED_MAX_REPL:
        raise Reject('sed replacement too long (output size)')
    while j < len(s) and s[j] not in ';\n \t':
        if s[j] not in SED_FLAG_CHARS and not s[j].isdigit():
            raise Reject(f'sed s flag not allowed: {s[j]}')
        j += 1
    return j


def _sed_trans(s, i):
    """y/SOURCE/DEST/ transliterates characters; it takes no flags."""
    if i >= len(s):
        raise Reject('incomplete sed y command')
    d = s[i]
    if d in '\\\n;' or d.isalnum() or d in ' \t':
        raise Reject('bad sed delimiter')
    j = _sed_until(s, i + 1, d)
    start = j
    j = _sed_until(s, j, d)
    if j - start > SED_MAX_REPL:
        raise Reject('sed y part too long')
    return j


def _sed_script_ok(s):
    """Check one script and return the command letters it uses (for the caps)."""
    n = len(s)
    i = 0
    cmds = []
    while True:
        while i < n and s[i] in ' \t;\n':
            i += 1
        if i >= n:
            return cmds
        j = _sed_address(s, i)
        if j == i and s[i] == ',':
            raise Reject('sed range without start')
        i = j
        if i < n and s[i] == ',':
            i = _sed_address2(s, i + 1)
        while i < n and s[i] in ' \t':
            i += 1
        if i < n and s[i] == '!':
            i += 1
            while i < n and s[i] in ' \t':
                i += 1
        if i >= n:
            raise Reject('sed address without command')
        c = s[i]
        if c in SED_ALLOWED_CMDS:
            i += 1
            cmds.append(c)
        elif c == 's':
            i = _sed_subst(s, i + 1)
            cmds.append('s')
        elif c == 'y':
            i = _sed_trans(s, i + 1)
            cmds.append('y')
        else:
            raise Reject(f'sed command not allowed: {c}')
        while i < n and s[i] in ' \t':
            i += 1
        if i < n and s[i] not in ';\n':
            raise Reject('unexpected text in sed script')


def _sed(args, cwd):
    scripts, skip, pos = [], set(), []
    have_e = False
    i, after_dd = 0, False
    while i < len(args):
        t = args[i].text
        if after_dd:
            pos.append(i)
        elif t == '--':
            after_dd = True
        elif t.startswith('--'):
            name, eq, val = t.partition('=')
            if name.startswith('--in-place') or name.startswith('--file'):
                raise Reject('sed in-place or script file')
            if name not in SED_LONG_OK:
                raise Reject(f'sed option not allowed: {name}')
            if name == '--expression':
                have_e = True
                skip.add(i)
                if eq:
                    scripts.append(val)
                else:
                    if i + 1 >= len(args):
                        raise Reject('sed --expression without script')
                    scripts.append(args[i + 1].text)
                    skip.add(i + 1)
                    i += 1
            elif name == '--line-length' and not eq:
                skip.add(i + 1)
                i += 1
        elif t.startswith('-') and len(t) > 1:
            letters = t[1:]
            if 'i' in letters or 'f' in letters:
                raise Reject('sed -i or -f')
            for ch in letters:
                if ch not in SED_SHORT_OK:
                    raise Reject(f'sed option not allowed: -{ch}')
                if ch in 'el':
                    break  # the rest of the cluster is the value (a script)
            for k, ch in enumerate(letters):
                if ch in 'el':
                    rest = letters[k + 1:]
                    if ch == 'e':
                        have_e = True
                    if rest:
                        if ch == 'e':
                            scripts.append(rest)
                        skip.add(i)
                    else:
                        if i + 1 >= len(args):
                            raise Reject('sed option without value')
                        if ch == 'e':
                            scripts.append(args[i + 1].text)
                        skip.add(i + 1)
                        i += 1
                    break
        else:
            pos.append(i)
        i += 1
    if not have_e:
        if not pos:
            raise Reject('sed without script')
        scripts.append(args[pos[0]].text)
        skip.add(pos[0])
    cmds = []
    for s in scripts:
        cmds.extend(_sed_script_ok(s))
    if len(cmds) > SED_MAX_COMMANDS:
        raise Reject('sed script has too many commands (output size)')
    if 'H' in cmds and any(c in cmds for c in 'gGxs'):
        # H grows the hold space every line; printing it again each line is
        # quadratic in the input size.
        raise Reject('sed H with a command that prints the hold space')
    if 'G' in cmds and any(c in cmds for c in 'hHx'):
        # G appends the hold space; h, H or x store the result back, which doubles
        # the data on every line.
        raise Reject('sed G with a command that stores into the hold space (doubling)')
    _check_args(args, cwd, skip=frozenset(skip))


# ---------------------------------------------------------------- awk
def _awk(args, cwd):
    progs, skip, pos = [], set(), []
    have_e = False
    i, after_dd = 0, False
    while i < len(args):
        t = args[i].text
        if after_dd:
            pos.append(i)
        elif t == '--':
            after_dd = True
        elif t.startswith('--'):
            name, eq, val = t.partition('=')
            if name not in AWK_LONG_OK:
                raise Reject(f'awk option not allowed: {name}')
            if name == '--source':
                have_e = True
                if eq:
                    progs.append(val)
                    skip.add(i)
                else:
                    if i + 1 >= len(args):
                        raise Reject('awk --source without program')
                    progs.append(args[i + 1].text)
                    skip.add(i + 1)
                    i += 1
            elif name in ('--field-separator', '--assign') and not eq:
                skip.add(i + 1)
                i += 1
        elif t.startswith('-') and len(t) > 1:
            letters = t[1:]
            for k, ch in enumerate(letters):
                if ch in AWK_BAD_LETTERS:
                    raise Reject(f'awk option not allowed: -{ch}')
                if ch in 'vF':
                    if letters[k + 1:]:
                        skip.add(i)
                    else:
                        if i + 1 >= len(args):
                            raise Reject('awk option without value')
                        skip.add(i + 1)
                        i += 1
                    break
                if ch == 'e':
                    have_e = True
                    rest = letters[k + 1:]
                    if rest:
                        progs.append(rest)
                        skip.add(i)
                    else:
                        if i + 1 >= len(args):
                            raise Reject('awk -e without program')
                        progs.append(args[i + 1].text)
                        skip.add(i + 1)
                        i += 1
                    break
        else:
            pos.append(i)
        i += 1
    if not have_e:
        if not pos:
            raise Reject('awk without program')
        progs.append(args[pos[0]].text)
        skip.add(pos[0])
    for w in args:
        # -v NAME=VALUE, an operand NAME=VALUE and a program assignment: a number
        # of five or more digits can size a string, a width or a loop bound.
        if re.search(r'[A-Za-z_]\w*=(?!=).*[0-9]{5}', w.text):
            raise Reject('awk assignment of a number with five or more digits')
    for p in progs:
        for bad in AWK_BAD_PROGRAM:
            if bad in p:
                raise Reject(f'awk program contains {bad}')
        _awk_output_redirect(p)
    _check_args(args, cwd, skip=frozenset(skip))


# ---------------------------------------------------------------- git
def _git_count_flag(t):
    """-c, -ci, -cn: a cluster of git grep no-value letters that starts with c is
    the count flag (git grep -c)."""
    return len(t) > 1 and t[1] == 'c' and all(ch in GREP_SHORT['git'][1] for ch in t[1:])


def _git_forbidden(args, sub=None):
    for w in args:
        t = w.text
        # Before the subcommand -c is a config override (rejected by _git). After
        # 'grep' it is the count flag, and after log, diff and show -c / -cc is the
        # combined-diff display option, so only there does it pass.
        count_flag = (sub == 'grep' and _git_count_flag(t)) or (
            sub in ('log', 'diff', 'show') and t in ('-c', '-cc'))
        if t.partition('=')[0] in GIT_DISPLAY_LONG:
            continue  # display-only options that merely start with --output
        if (t.startswith(GIT_FORBIDDEN_PREFIX) or t.startswith('-O')
                or (not count_flag and (t == '-c' or (t.startswith('-c') and not t.startswith('--'))))):
            raise Reject(f'git option not allowed: {t}')
        # A letter O inside a short cluster is git grep -O (-nOcalc runs calc).
        if t.startswith('-') and not t.startswith('--') and 'O' in t[1:]:
            raise Reject(f'git option not allowed: {t}')
    _long_prefix_reject([w for w in args if w.text.partition('=')[0] not in GIT_DISPLAY_LONG],
                        GIT_DANGEROUS_LONG)


def _git_repo_unsafe(gcwd, writes_safe):
    """git runs config-named programs (core.fsmonitor and friends), so it must
    not discover a repository the session can write. The repo is the nearest
    folder with a .git entry at or above gcwd. Unsafe when that folder is in
    build/ or scratch, or when gcwd is in build/scratch and this command writes
    there (a .git could be created by the same command)."""
    if writes_safe and _in_safe_scratch_or_build(gcwd):
        return True
    d = gcwd
    while True:
        if os.path.exists(ntpath.join(d, '.git')) or _gitdir_layout(d):
            return _in_safe_scratch_or_build(d)
        parent = ntpath.dirname(d)
        if parent == d:
            return False
        d = parent


def _gitdir_layout(d):
    """git accepts a folder as a bare repository when it holds HEAD, objects/ and
    refs/ (round 4: build/HEAD + objects/ + refs/ made 'git -C build' use build
    as the repository, and its config ran diff.external)."""
    return (os.path.isfile(ntpath.join(d, 'HEAD')) and os.path.isdir(ntpath.join(d, 'objects'))
            and os.path.isdir(ntpath.join(d, 'refs')))


def _git_list_check(rest, listish_flags, value_flags, flags, allow_pos_without_list=False):
    """Shared shape for branch/tag: flags, optional value options, <=1 pattern."""
    pos, listish, i = [], False, 0
    while i < len(rest):
        t = rest[i].text
        if t in value_flags:
            if i + 1 >= len(rest):
                raise Reject(f'git option needs a value: {t}')
            i += 2
            continue
        if t.startswith(tuple(f + '=' for f in value_flags)):
            i += 1
            continue
        if t in flags:
            if t in listish_flags:
                listish = True
            i += 1
            continue
        if t.startswith('-'):
            raise Reject(f'git option not allowed: {t}')
        pos.append(t)
        i += 1
    if len(pos) > 1 or (pos and not listish and not allow_pos_without_list):
        raise Reject('git pattern argument without a list option')


GIT_REMOTE_SECTION_RE = re.compile(r'\s*\[remote "([^"]+)"\]')


def _git_remote_names(gcwd):
    """Remote names configured for the repository git uses from gcwd. A bare word
    that is not a configured remote is taken as a local repository path, which may
    be a folder the session wrote (build/HEAD, build/config). Unknown layout: none."""
    d = gcwd
    while True:
        dot = ntpath.join(d, '.git')
        if os.path.isdir(dot):
            cfg_dir = dot
            break
        if os.path.isfile(dot):
            try:
                with open(dot, encoding='utf-8', errors='replace') as fh:
                    m = re.match(r'gitdir:\s*(.+?)\s*$', fh.readline())
            except OSError:
                return set()
            if not m:
                return set()
            cfg_dir = ntpath.normpath(ntpath.join(d, m.group(1)))
            break
        parent = ntpath.dirname(d)
        if parent == d:
            return set()
        d = parent
    names = set()
    try:
        with open(ntpath.join(cfg_dir, 'config'), encoding='utf-8', errors='replace') as fh:
            for line in fh:
                m = GIT_REMOTE_SECTION_RE.match(line)
                if m:
                    names.add(m.group(1))
    except OSError:
        return set()
    return names


def _git(args, cwd, writes_safe=False):
    i, gcwd = 0, cwd
    while i < len(args) and args[i].text.startswith('-'):
        t = args[i].text
        if t == '-C' or (t.startswith('-C') and not t.startswith('--') and len(t) > 2):
            if t == '-C':
                if i + 1 >= len(args) or args[i + 1].glob:
                    raise Reject('git -C without a directory')
                target, i = args[i + 1].text, i + 2
            else:
                target, i = t[2:], i + 1
            p = resolve_path(target, gcwd)
            if p is None or p == DEVNULL or not _read_safe(p):
                raise Reject('git -C outside read-safe places')
            gcwd = p
        elif t in ('--no-pager', '-P'):
            i += 1
        else:
            raise Reject(f'git global option not allowed: {t}')
    if i >= len(args):
        raise Reject('git without subcommand')
    if _git_repo_unsafe(gcwd, writes_safe):
        raise Reject('git would use a repository the session can write')
    sub, rest = args[i].text, args[i + 1:]
    _git_forbidden(rest, sub)
    texts = [w.text for w in rest]
    if sub == 'grep':
        _grep_like(rest, gcwd, 'git')
        return
    if sub in GIT_READ_SUBCOMMANDS:
        if sub == 'blame':
            # -S<revs-file> takes its file attached: check that tail whole, as a path.
            attached = {j for j, w in enumerate(rest)
                        if w.text.startswith('-S') and len(w.text) > 2}
            for j in attached:
                _check_path_text(_neutral(rest[j].text[2:]), gcwd)
            _check_args(rest, gcwd, skip=frozenset(attached))
            return
        _check_args(rest, gcwd)
        if sub == 'diff':
            # git diff --no-index recurses into directory operands
            _walk_guard(rest, gcwd, default_root=False)
        return
    if sub == 'fetch':
        positional = []
        for t in texts:
            if t.startswith('-'):
                if t not in GIT_FETCH_FLAGS:
                    raise Reject(f'git fetch option not allowed: {t}')
            elif not GIT_REMOTE_NAME.fullmatch(t):
                raise Reject('git fetch refspec or path not allowed')
            else:
                positional.append(t)
        if positional and positional[0] not in _git_remote_names(gcwd):
            raise Reject('git fetch names a path or an unconfigured remote')
        return
    if sub == 'remote':
        ok = (texts == [] or texts == ['-v'] or texts == ['--verbose']
              or (texts[:1] == ['show'] and len(texts) == 2 and GIT_REMOTE_NAME.fullmatch(texts[1]))
              or texts == ['show']
              or (texts[:1] == ['get-url'] and len(texts) == 2 and GIT_REMOTE_NAME.fullmatch(texts[1])))
        if not ok:
            raise Reject('git remote form not allowed')
        return
    if sub == 'branch':
        _git_list_check(rest, GIT_BRANCH_LISTISH, frozenset({'--format'}), GIT_BRANCH_FLAGS)
        return
    if sub == 'tag':
        _git_list_check(rest, GIT_TAG_LISTISH, frozenset({'--sort', '--format'}), GIT_TAG_FLAGS)
        return
    if sub == 'config':
        modes, pos = set(), []
        for t in texts:
            if t in GIT_CONFIG_FLAGS:
                if t in GIT_CONFIG_MODES:
                    modes.add(t)
            elif t.startswith('-'):
                raise Reject(f'git config option not allowed: {t}')
            else:
                pos.append(t)
        if modes & {'--list', '-l'}:
            if pos or len(modes) > 1:
                raise Reject('git config list form with arguments')
        elif len(pos) != 1 or len(modes) > 1:
            raise Reject('git config would set a value or has no key')
        if (modes & {'--list', '-l', '--get-regexp'}) and '--local' not in texts:
            raise Reject('git config list or regexp read needs --local')
        return
    if sub == 'stash':
        if not texts or texts[0] not in ('list', 'show'):
            raise Reject('git stash form not allowed')
        _check_args(rest[1:], gcwd)
        return
    if sub == 'submodule':
        if not texts or texts[0] not in ('status', 'summary'):
            raise Reject('git submodule form not allowed')
        _check_args(rest[1:], gcwd)
        return
    if sub == 'worktree':
        if not texts or texts[0] != 'list' or any(t not in ('list', '--porcelain', '-v', '--verbose') for t in texts):
            raise Reject('git worktree form not allowed')
        return
    if sub == 'reflog':
        if texts and texts[0] != 'show':
            raise Reject('git reflog form not allowed')
        _check_args(rest[1:] if texts else [], gcwd)
        return
    raise Reject(f'git subcommand not allowed: {sub}')


# ---------------------------------------------------------------- npm / node / python
def _npm(args, cwd):
    """npm finds a package.json from the working folder upwards and runs its
    lifecycle scripts, so npm may not run from build/ or the scratch root, where
    a package.json could have been planted."""
    if _in_safe_scratch_or_build(cwd):
        raise Reject('npm run from build or scratch')
    tt = tuple(w.text for w in args)
    if tt in NPM_EXACT:
        return
    if tt[:2] == ('run', 'verify:package') and (
            len(tt) == 2 or (len(tt) == 5 and tt[2:4] == ('--', '--channel')
                             and NPM_FIELD_RE.fullmatch(tt[4]))):
        return
    if tt[:1] == ('ls',) and (len(tt) == 1 or (len(tt) == 2 and NPM_NAME_RE.fullmatch(tt[1]))):
        return
    # Round 7: npm view/info/show/search are not here: they contact the registry
    # with a package name the caller picks.
    raise Reject('npm form not allowed')


def _node(name, args):
    tt = tuple(w.text for w in args)
    if name == 'node' and tt in (('--version',), ('-v',)):
        return
    if name == 'npx' and tt == ('--version',):
        return
    raise Reject(f'{name} form not allowed')


def _py_inline_ok(code):
    for m in re.finditer(r'\bimport\s+([ \t\w.,]+)', code):
        for part in m.group(1).split(','):
            name = part.strip().split(' ')[0] if part.strip() else ''
            if not name:
                continue
            if name == 'os.path':
                continue
            if name.split('.')[0] not in PY_INLINE_IMPORTS:
                return False
    for m in re.finditer(r'\bfrom\s+([A-Za-z_][\w.]*)\s+import\b', code):
        root = m.group(1)
        if root != 'os.path' and root.split('.')[0] not in PY_INLINE_IMPORTS:
            return False
    if re.search(r'\bos\b(?!\.path\b)', code):
        return False
    for tok in re.findall(r'[A-Za-z_]\w*', code):
        if tok in PY_INLINE_BAD:
            return False
    for m in re.finditer(r'\bopen\s*\(', code):
        tail = code[m.end():]
        close = tail.find(')')
        args = tail[:close] if close >= 0 else tail
        if ',' in args:
            mode = args.split(',', 1)[1].strip()
            if not re.fullmatch(r'''(?:mode\s*=\s*)?(['"])(rb?|rt?|)\1''', mode):
                return False
    return True


# pytest options that name a file or folder the run writes. The value must be
# write-safe (PROJECT/build or the scratch root): a report or a base temp folder
# in the project would overwrite or (--basetemp) delete project files.
PYTEST_WRITE_OPTS = frozenset({
    '--basetemp', '--junitxml', '--junit-xml', '--html', '--report-log', '--log-file',
    '--cache-dir', '--cov-report',
})
# Options whose value is a config file or folder that pytest reads. It must be
# read-safe and outside build/scratch (a planted ini can add options and plugins).
PYTEST_READ_PATH_OPTS = frozenset({'--rootdir', '--confcutdir', '--config-file'})
# -o KEY=VALUE overrides an ini key. Only keys that cannot add options, plugins,
# paths or output files are accepted (addopts, cache_dir, testpaths, pythonpath
# and the junit/log file keys are not).
PYTEST_INI_SAFE = frozenset({
    'python_files', 'python_classes', 'python_functions', 'log_cli', 'log_cli_level',
    'log_level', 'console_output_style', 'filterwarnings', 'markers', 'xfail_strict',
    'minversion', 'junit_family', 'junit_logging', 'log_format', 'log_date_format',
})
PYTEST_PLUGIN_OFF_RE = re.compile(r'no:[A-Za-z0-9_.-]+')
PYTEST_COV_TYPES_WITH_DEST = frozenset({'html', 'xml', 'json', 'lcov', 'annotate'})


def _pytest_value(args, i, t):
    """(value, next index, value word) of an option written as NAME=VALUE, NAME
    VALUE or (short options) NAMEVALUE."""
    if t.startswith('--') and '=' in t:
        return t.split('=', 1)[1], i + 1, args[i]
    if len(t) > 2 and not t.startswith('--'):
        return t[2:], i + 1, args[i]
    if i + 1 >= len(args):
        raise Reject(f'pytest option {t} without a value')
    return args[i + 1].text, i + 2, args[i + 1]


def _pytest_options(args, cwd):
    """Take out the options that carry a path or a plugin name and judge them
    (see PYTEST_WRITE_OPTS and friends); return the other words for the generic
    checks. -p takes only no:NAME (it switches a plugin off): any other name
    imports a module."""
    keep, i, n = [], 0, len(args)
    while i < n:
        w = args[i]
        t = w.text
        if t == '--':
            keep.extend(args[i:])
            break
        name = t.partition('=')[0] if t.startswith('--') else t[:2]
        if name not in ('-p', '-o', '-c', '--override-ini') and name not in PYTEST_WRITE_OPTS \
                and name not in PYTEST_READ_PATH_OPTS:
            keep.append(w)
            i += 1
            continue
        val, i, vw = _pytest_value(args, i, t)
        if w.glob or vw.glob:
            raise Reject('glob in a pytest option value')
        if name == '-p':
            if not PYTEST_PLUGIN_OFF_RE.fullmatch(val):
                raise Reject('pytest -p loads a plugin (only -p no:NAME is allowed)')
        elif name in ('-o', '--override-ini'):
            if val.partition('=')[0] not in PYTEST_INI_SAFE:
                raise Reject('pytest ini override key not allowed')
        elif name in ('-c',) or name in PYTEST_READ_PATH_OPTS:
            p = resolve_path(val, cwd)
            if p is None or p == DEVNULL or not _read_safe(p) or _in_safe_scratch_or_build(p):
                raise Reject('pytest config path outside the project or inside build/scratch')
        elif name == '--cov-report':
            kind, colon, dest = val.partition(':')
            if not re.fullmatch(r'[A-Za-z-]+', kind):
                raise Reject('pytest --cov-report form not allowed')
            if kind in PYTEST_COV_TYPES_WITH_DEST and colon and dest:
                _write_target_word(_plain_word(dest), cwd)
        else:
            _write_target_word(_plain_word(val), cwd)
            if name == '--basetemp':
                # pytest clears its base temp folder, so it may not be a root itself.
                p = resolve_path(val, cwd)
                if ntpath.normcase(p) in tuple(ntpath.normcase(r) for r in (BUILD_DIR, SCRATCH) if r):
                    raise Reject('pytest --basetemp is a root that pytest would clear')
    return keep


def _pytest(args, cwd):
    """pytest runs whatever test and conftest modules it collects, so it may
    only collect from a scope that the session cannot write: never build/,
    never the scratch root, never a folder that contains either (bare pytest
    at the project root would collect build/). Writes of .pytest_cache and
    __pycache__ inside the project are accepted. Options that write a file or
    folder need a write-safe value, config paths must be read-safe, -p takes only
    no:NAME, -o only safe keys (see _pytest_options); every other option must be
    in PYTEST_LONG_OK or PYTEST_SHORT_OK, so abbreviations and plugin options
    (--pastebin, --cov, -n ...) are refused."""
    if _in_safe_scratch_or_build(cwd):
        raise Reject('pytest run from build or scratch (sys.path holds the folder)')
    if any(w.text.startswith('@') for w in args):
        # Round 7: pytest reads '@FILE' words as argument files, from any position
        # (also after --); the names inside such a file are not checked.
        raise Reject('pytest @argsfile word')
    if args and all(w.text in ('--version', '-V', '--help', '-h') for w in args):
        return  # prints and exits; collects nothing and writes no cache
    args = _pytest_options(args, cwd)
    _long_allow(args, PYTEST_LONG_OK)
    skip = _scan_short(args, PYTEST_SHORT_OK, PYTEST_SHORT_VALUE)
    positional = []
    after_dd = False
    for idx, w in enumerate(args):
        if idx in skip:
            continue
        t = w.text
        if after_dd:
            positional.append(w)
        elif t == '--':
            after_dd = True
        elif t.startswith('-') and t != '-':
            continue
        else:
            positional.append(w)
    if not positional:
        if any(w.text in ('--version', '-V', '--help', '-h') for w in args):
            return  # prints and exits; collects nothing
        if not _test_scope_ok(cwd):
            raise Reject('pytest from a folder that contains build or scratch')
        return
    for w in positional:
        # tests/test_x.py::test_name[param]: the path is the part before '::'.
        base = w.text.split('::', 1)[0]
        _check_word_path(_Word(base, base, w.glob, w.quoted, w.pwd), cwd)
        p = resolve_path(base, cwd)
        if p is None or not _test_scope_ok(p):
            raise Reject('pytest path inside build or scratch, or a parent of them')


def _python(args, cwd):
    # python -m puts the working folder first on sys.path: from build/ or the
    # scratch root a planted json.py or pytest.py would run.
    if _in_safe_scratch_or_build(cwd):
        raise Reject('python run from build or scratch')
    if args and args[0].text == '-B':
        args = args[1:]  # -B (no bytecode) is accepted but no longer required
    tt = tuple(w.text for w in args)
    if tt in (('--version',), ('-V',), ('-VV',)):
        return
    if len(args) >= 2 and tt[0] == '-m' and tt[1] == 'pytest' \
            and not args[0].glob and not args[1].glob:
        _pytest(args[2:], cwd)
        return
    if len(args) >= 2 and tt[0] == '-m' and tt[1] == 'json.tool':
        pos, i = [], 2
        while i < len(args):
            t = args[i].text
            if t == '--indent':
                i += 2
                continue
            if t in ('--sort-keys', '--no-ensure-ascii', '--json-lines', '--compact', '--tab', '--no-indent'):
                i += 1
                continue
            if t.startswith('-') and t != '-':
                raise Reject(f'json.tool option not allowed: {t}')
            pos.append(args[i])
            i += 1
        if len(pos) > 1:
            raise Reject('json.tool with an output file')
        _check_args(pos, cwd)
        return
    if len(args) >= 2 and tt[0] == '-c':
        if not ALLOW_PYTHON_INLINE_READONLY:
            raise Reject('inline python is disabled')
        if len(args) != 2 or not _py_inline_ok(tt[1]):
            raise Reject('inline python is not read-only')
        return
    raise Reject('python form not allowed')


# ---------------------------------------------------------------- curl
def _writeout_check(v):
    """A -w format may only print: every % is %% or %{NAME} with NAME in
    CURL_WRITEOUT_VARS. %output{FILE}, %header{...} and the rest are refused."""
    for m in re.finditer(r'%', v):
        rest = v[m.end():]
        if rest.startswith('%'):
            continue
        nm = re.match(r'\{([a-z_]+)\}', rest)
        if not nm or nm.group(1) not in CURL_WRITEOUT_VARS:
            raise Reject('curl write-out format not allowed')


def _curl(args):
    # No argument may contain '@': that covers -H@file, -w@file, -sw@file and
    # the @file form of any value. Header and write-out values are kept, with
    # the '@' ban applied to every word.
    if any('@' in w.text for w in args):
        raise Reject('curl argument contains @')
    urls, i, n, after_dd = [], 0, len(args), False
    while i < n:
        t = args[i].text
        if after_dd:
            urls.append(t)
        elif t == '--':
            after_dd = True
        elif t.startswith('--'):
            name, eq, val = t.partition('=')
            if name in CURL_LONG_NOARG and not eq:
                pass
            elif name in CURL_LONG_VALUE or name in ('--output', '--dump-header'):
                if eq:
                    v = val
                else:
                    if i + 1 >= n:
                        raise Reject('curl option without value')
                    v = args[i + 1].text
                    i += 1
                if name == '--url':
                    urls.append(v)
                elif name in CURL_CAPS:
                    _curl_cap(name, v)
                elif name in ('--output', '--dump-header') and v != DEVNULL:
                    raise Reject('curl output to a file')
            else:
                raise Reject(f'curl option not allowed: {name}')
        elif t.startswith('-') and len(t) > 1:
            letters, k = t[1:], 0
            while k < len(letters):
                ch = letters[k]
                if ch in CURL_NOARG_LETTERS:
                    k += 1
                    continue
                if ch in CURL_VALUE_SHORT or ch in 'XoD':
                    rest = letters[k + 1:]
                    if rest:
                        v = rest
                    else:
                        if i + 1 >= n:
                            raise Reject('curl option without value')
                        v = args[i + 1].text
                        i += 1
                    if ch == 'w':
                        _writeout_check(v)
                    if ch == 'm':
                        _curl_cap('--max-time', v)
                    if ch == 'H' and re.search(r'method', v.partition(':')[0], re.I):
                        raise Reject('curl header can override the HTTP method')
                    if ch == 'X' and v not in ('GET', 'HEAD'):
                        raise Reject('curl method not GET or HEAD')
                    if ch in 'oD' and v != DEVNULL:
                        raise Reject('curl output to a file')
                    break
                raise Reject(f'curl option not allowed: -{ch}')
        else:
            urls.append(t)
        i += 1
    if not urls:
        raise Reject('curl without URL')
    for u in urls:
        if not _curl_url_ok(u):
            raise Reject('curl URL not an allowed read-only local or GitHub address')


CURL_CAPS = {'--retry': 5, '--max-time': 600, '--connect-timeout': 60}


def _curl_cap(name, v):
    """Round 7: --retry, --max-time (-m) and --connect-timeout take a plain
    number up to a cap, so a request cannot be kept open or retried for ages."""
    if not re.fullmatch(r'[0-9]{1,5}(?:\.[0-9]{1,3})?', v) or float(v) > CURL_CAPS[name]:
        raise Reject(f'curl {name} not a number within its cap')
    if name == '--retry' and '.' in v:
        raise Reject('curl --retry not a whole number')


def _curl_url_ok(u):
    if re.search(r'[\[\]{}]', u):
        return False  # URL globbing ([0-99999], {a,b}) multiplies the requests
    if CURL_URL_RE.fullmatch(u):
        return True
    if CURL_MOCK_RE.fullmatch(u):
        return not CURL_MOCK_BAD_RE.search(u)
    m = CURL_LOCAL_RE.fullmatch(u)
    if not m:
        return False
    if m.group(1) is not None and not 1 <= int(m.group(1)) <= 65535:
        return False  # not a port (localhost:80010)
    path = m.group(2) or '/'
    if not CURL_PATH_CHARS_RE.fullmatch(path):
        return False  # ';' path parameters, escapes and '\' could name another path
    return path in CURL_LOCAL_PATHS


# ---------------------------------------------------------------- file-system heads
def _mkdir(args, cwd):
    """Round 7: -p is optional (every target must be write-safe anyway); -m and
    every other option still reject."""
    targets = []
    for w in args:
        if w.text == '-p':
            continue
        if w.text.startswith('-') and w.text != '-':
            raise Reject('mkdir option other than -p')
        targets.append(w)
    if not targets:
        raise Reject('mkdir without target')
    for w in targets:
        _write_target_word(w, cwd)


def _touch(args, cwd):
    targets, i = [], 0
    while i < len(args):
        t = args[i].text
        if t == '--':
            targets.extend(args[i + 1:])  # everything after -- is a file name
            break
        if t in ('-d', '-r', '-t'):
            if i + 1 >= len(args):
                raise Reject('touch option without value')
            if t == '-r':
                _check_word_path(args[i + 1], cwd)
            i += 2
            continue
        if t in ('-a', '-c', '-m', '-h', '--no-create'):
            i += 1
            continue
        if t.startswith('-') and t != '-':
            raise Reject('touch option not allowed')
        targets.append(args[i])
        i += 1
    if not targets:
        raise Reject('touch without target')
    for w in targets:
        _write_target_word(w, cwd)


def _uniq(args, cwd):
    pos, skip, i = [], set(), 0
    while i < len(args):
        t = args[i].text
        if t in ('-f', '-s', '-w'):
            skip.add(i + 1)
            i += 2
            continue
        if t.startswith('-') and t != '-':
            i += 1
            continue
        pos.append(i)
        i += 1
    if len(pos) > 1:
        raise Reject('uniq with an output file')
    _check_args(args, cwd, skip=frozenset(skip))


def _xxd(args, cwd):
    _reject_args(args, short='r')
    pos, skip, i = [], set(), 0
    while i < len(args):
        t = args[i].text
        if t in ('-c', '-g', '-l', '-s', '-o', '-n'):
            skip.add(i + 1)
            i += 2
            continue
        if t.startswith('-') and t != '-':
            i += 1
            continue
        pos.append(i)
        i += 1
    if len(pos) > 1:
        raise Reject('xxd with an output file')
    _check_args(args, cwd, skip=frozenset(skip))


def _archive_args_ok(args, cwd, ok_long, ok_short_letters, need):
    have = False
    for w in args:
        t = w.text
        if t.startswith('--'):
            name = t.split('=', 1)[0]
            if name not in ok_long:
                raise Reject(f'archive option not allowed: {name}')
            if name in need:
                have = True
        elif t.startswith('-') and t != '-':
            for ch in t[1:]:
                if ch in need:
                    have = True
                elif ch not in ok_short_letters:
                    raise Reject(f'archive option not allowed: -{ch}')
    if not have:
        raise Reject('archive command without a listing option')
    _check_args(args, cwd)


def _recursive_long(text):
    """A long option that is --recursive or an abbreviation of it (round 4:
    'ls --recursive' skipped the link check that 'ls -R' ran)."""
    if not text.startswith('--'):
        return False
    name = text.split('=', 1)[0][2:]
    return name != '' and 'recursive'.startswith(name)


def _unzip(args, cwd):
    opts = [w.text for w in args if w.text.startswith('-') and w.text != '-']
    for o in opts:
        if not re.fullmatch(r'-[lZtvq]+', o):
            raise Reject(f'unzip option not allowed: {o}')
    if not any(set('lZt') & set(o) for o in opts):
        raise Reject('unzip without a listing option')
    targets = [w for w in args if not (w.text.startswith('-') and w.text != '-')]
    if not targets:
        raise Reject('unzip without archive')
    _check_args(args, cwd)


def _tar(args, cwd):
    have_t, i = False, 0
    while i < len(args):
        t = args[i].text
        if t.startswith('--'):
            name = t.split('=', 1)[0]
            if name == '--list':
                have_t = True
            elif name not in ('--verbose', '--gzip', '--bzip2', '--xz', '--file'):
                raise Reject(f'tar option not allowed: {name}')
        elif (t.startswith('-') and len(t) > 1) or (i == 0 and t):
            # The old style reads the first word as a cluster without a dash
            # (tar xf a.tgz -t runs extract and list together): its letters count.
            letters = t[1:] if t.startswith('-') else t
            for ch in letters:
                if ch == 't':
                    have_t = True
                elif ch in 'vzjJ':
                    pass
                elif ch == 'f':
                    break
                else:
                    raise Reject(f'tar option not allowed: -{ch}')
        i += 1
    if not have_t:
        raise Reject('tar without list option')
    _check_args(args, cwd)


def _7z(args, cwd):
    if not args or args[0].text != 'l':
        raise Reject('7z only with the l (list) command')
    if any('@' in w.text for w in args):
        # 7-Zip reads an @file word as a list of names; the names are not checked.
        raise Reject('7z @listfile or response file')
    for w in args[1:]:
        if w.text.startswith('-') and w.text not in ('-slt', '-ba', '-bd'):
            raise Reject(f'7z option not allowed: {w.text}')
    _check_args(args[1:], cwd)


# ---------------------------------------------------------------- dispatch
def _sort(args, cwd):
    """sort -o writes its output file, so it is refused. -T names the folder for
    temporary files and is allowed only when that folder is write-safe."""
    _long_allow(args, SORT_LONG_OK | frozenset({'--temporary-directory'}))
    rest, tmp = [], []
    i, n = 0, len(args)
    while i < n:
        t = args[i].text
        if t == '--':
            rest.extend(args[i:])
            break
        if t in ('-T', '--temporary-directory'):
            if i + 1 >= n:
                raise Reject('sort -T without a directory')
            tmp.append(args[i + 1])
            i += 2
            continue
        if t.startswith('--temporary-directory='):
            tmp.append(_plain_word(t.split('=', 1)[1]))
        elif t.startswith('-T') and not t.startswith('--') and len(t) > 2:
            tmp.append(_plain_word(t[2:]))
        else:
            rest.append(args[i])
        i += 1
    for w in tmp:
        _write_target_word(w, cwd)
    _scan_short(rest, SORT_SHORT_OK, frozenset('kt'), deny=frozenset('oTS'))
    _check_args(rest, cwd, nonpath=frozenset('kt'))


def _date(args, cwd):
    """Round 7: a bare date operand (date 010101012027) sets the clock, so every
    operand must be a +FORMAT; -s/--set and -f/--file are refused too."""
    _long_allow(args, DATE_LONG_OK)
    skip = _scan_short(args, DATE_SHORT_OK, frozenset('dr'), deny=frozenset('sf'))
    after_dd = False
    for idx, w in enumerate(args):
        t = w.text
        if idx in skip:
            continue
        if after_dd or not (t.startswith('-') and t != '-'):
            if not t.startswith('+'):
                raise Reject('date operand is not a +FORMAT (it would set the clock)')
        elif t == '--':
            after_dd = True
    _check_args(args, cwd)


def _hostname(args, cwd):
    _long_allow(args, HOSTNAME_OK)
    _scan_short(args, frozenset('sfdiIAa'), frozenset())
    if any(not w.text.startswith('-') for w in args):
        raise Reject('hostname with a name argument would set the name')


def _file(args, cwd):
    _long_allow(args, FILE_LONG_OK)
    _scan_short(args, FILE_SHORT_OK, frozenset('Fe'))
    _check_args(args, cwd)


def _tree(args, cwd):
    _long_allow(args, TREE_LONG_OK)
    _scan_short(args, None, frozenset('LPIH'), deny=TREE_SHORT_DENY)
    _check_args(args, cwd)
    _walk_guard(args, cwd, default_root=True)


def _wc(args, cwd):
    _long_prefix_reject(args, ('--files0-from',))
    _check_args(args, cwd)


def _sum_check(args, cwd):
    """md5sum/sha*sum -c reads the names listed in a file, so names outside the
    project would be read. Only plain hashing is allowed: every long option must
    be on SUM_LONG_OK (so --c and --ch are refused) and -c is refused."""
    _long_allow(args, SUM_LONG_OK)
    _reject_args(args, short='c')
    _check_args(args, cwd)


def _jq(args, cwd):
    """jq can read the environment ($ENV, env) or load modules (import,
    include, -L) and programs from files (-f). None of those are allowed."""
    joined = ' '.join(w.text for w in args)
    if JQ_BAD_TOKENS.search(joined):
        raise Reject('jq program reads the environment, imports modules or is unbounded')
    if re.search(r'[0-9]{5}', joined):
        raise Reject('jq number of five or more digits (output size)')
    # Round 7: user functions (def f: 1,f), exponent numbers (1e9), combinations,
    # infinite/nan and a string multiplied twice ("a"*9999*9999) are unbounded.
    if re.search(r'\bdef\b|\bcombinations\b|\binfinite\b|\bnan\b|[0-9.][eE][+-]?[0-9]', joined):
        raise Reject('jq function definition, exponent number or unbounded generator')
    if len(re.findall(r'\*', re.sub(r'"(?:\\.|[^"\\])*"', '""', joined))) > 1:
        raise Reject('jq program multiplies more than once (output size)')
    _long_prefix_reject(args, JQ_DENY_LONG)
    for w in args:
        t = w.text
        if t.startswith('-') and not t.startswith('--') and ('f' in t[1:] or 'L' in t[1:]):
            raise Reject('jq program file or library path')
    _check_args(args, cwd)


def _seq(args, cwd):
    """seq prints every number of its range. The line count is capped, and the
    estimated output size is capped at SEQ_MAX_BYTES: the width of each line is
    the length of a format (a %-conversion may print hundreds of characters) or,
    without one, the digits of the largest number, plus the separator."""
    vals, fmt, sep, i, n = [], '', '\n', 0, len(args)
    while i < n:
        t = args[i].text
        if t == '-w':
            i += 1
        elif t in ('-s', '-f'):
            if i + 1 >= n:
                raise Reject('seq option without value')
            if t == '-f':
                fmt = args[i + 1].text
            else:
                sep = args[i + 1].text
            i += 2
        elif t.startswith('-s') or t.startswith('-f'):
            if t[1] == 'f':
                fmt = t[2:]
            else:
                sep = t[2:]
            i += 1
        elif re.fullmatch(r'[-+]?[0-9.eE+-]+', t):
            try:
                vals.append(float(t))
            except ValueError:
                raise Reject('seq argument is not a number')
            i += 1
        else:
            raise Reject('seq argument not allowed')
    if re.search(r'[0-9]{5}', fmt):
        raise Reject('seq format with five or more digits (output size)')
    if not 1 <= len(vals) <= 3:
        raise Reject('seq form not allowed')
    if len(vals) == 1:
        first, incr, last = 1.0, 1.0, vals[0]
    elif len(vals) == 2:
        first, incr, last = vals[0], 1.0, vals[1]
    else:
        first, incr, last = vals
    if incr == 0:
        raise Reject('seq increment of zero')
    count = (last - first) / incr + 1
    if not count <= SEQ_MAX_COUNT:
        raise Reject('seq would print too many lines')
    try:
        digits = len(str(int(max(abs(first), abs(last))))) + 1
    except (OverflowError, ValueError):
        raise Reject('seq number out of range')
    per_line = (len(fmt) + sum(330 + max([int(x) for x in m.groups() if x] or [0])
                               for m in SEQ_CONV_RE.finditer(fmt))) if fmt else digits
    if count * (per_line + len(sep)) > SEQ_MAX_BYTES:
        raise Reject('seq output would be too large')


def _run_head(name, args, cwd, writes_safe=False):
    if name in PURE_READ_HEADS:
        # --files0-from makes the tool read a list of names from a file.
        _long_prefix_reject(args, ('--files0-from',))
        if name == 'printf':
            _printf(args)
        elif name in NO_PATH_HEADS:
            return
        elif name == 'jq':
            _jq(args, cwd)
        elif name == 'seq':
            _seq(args, cwd)
        elif name == 'wc':
            _wc(args, cwd)
        elif name in ('md5sum', 'sha1sum', 'sha256sum'):
            _sum_check(args, cwd)
        elif name == 'ps':
            _ps(args)
        elif name == 'graphify':
            _graphify(args, cwd)
        elif name == 'openspec':
            _openspec(args, cwd)
        elif name == 'cut':
            _check_args(args, cwd, nonpath=frozenset('dfcb'))
        elif name in ('grep', 'egrep', 'fgrep', 'zgrep'):
            _grep_like(args, cwd, 'grep')
        elif name == 'du':
            _check_args(args, cwd)
            _walk_guard(args, cwd, default_root=True)
        elif name == 'diff':
            _check_args(args, cwd)
            _walk_guard(args, cwd, default_root=False)
        elif name == 'ls':
            _check_args(args, cwd)
            if any(re.match(r'^-[A-Za-z]*R', w.text) or _recursive_long(w.text) for w in args):
                _walk_guard(args, cwd, default_root=True)
        elif name == 'tail':
            _tail(args, cwd)
        elif name in ('test', '['):
            _test_cmd(args, cwd)
        elif name in ('findstr', 'where'):
            _windows_search(name, args, cwd)
        else:
            _check_args(args, cwd)
    elif name == 'sort':
        _sort(args, cwd)
    elif name == 'uniq':
        _uniq(args, cwd)
    elif name == 'date':
        _date(args, cwd)
    elif name == 'hostname':
        _hostname(args, cwd)
    elif name == 'file':
        _file(args, cwd)
    elif name == 'tree':
        _tree(args, cwd)
    elif name == 'xxd':
        _xxd(args, cwd)
    elif name == 'find':
        _reject_args(args, exact=('-delete', '-exec', '-execdir', '-ok', '-okdir', '-fls',
                                  '-files0-from'),
                     prefix=('-fprint',))
        _check_args(args, cwd)
        _walk_guard(args, cwd, default_root=True)
    elif name == 'rg':
        _long_prefix_reject(args, RG_DENY_LONG)
        _grep_like(args, cwd, 'rg')
    elif name == 'sed':
        _sed(args, cwd)
    elif name in ('awk', 'gawk', 'mawk'):
        _awk(args, cwd)
    elif name == 'git':
        _git(args, cwd, writes_safe)
    elif name == 'npm':
        _npm(args, cwd)
    elif name in ('node', 'npx'):
        _node(name, args)
    elif name in ('python', 'python3', 'py'):
        _python(args, cwd)
    elif name == 'pytest':
        _pytest(args, cwd)
    elif name == 'curl':
        _curl(args)
    elif name == 'mkdir':
        _mkdir(args, cwd)
    elif name == 'touch':
        _touch(args, cwd)
    elif name == 'unzip':
        _unzip(args, cwd)
    elif name == 'tar':
        _tar(args, cwd)
    elif name == '7z':
        _7z(args, cwd)
    elif name == 'tasklist':
        if any(w.text.lower() not in TASKLIST_OK for w in args):
            raise Reject('tasklist option not allowed')
    elif name == 'netstat':
        if any(not NETSTAT_RE.fullmatch(w.text) for w in args):
            raise Reject('netstat option not allowed')
    else:
        raise Reject(f'no rule for {name}')


def _seg_writes(seg):
    """True when a simple command writes: any output redirect, touch or mkdir."""
    for x in seg:
        if isinstance(x, _Redir) and x.op not in ('<',):
            return True
    words = [x for x in seg if isinstance(x, _Word)]
    return bool(words) and words[0].text in ('touch', 'mkdir')


def _format_parens_ok(head, args, w):
    """An unquoted ( or ) survives the lexer only inside a --format= word (see
    _lex). It is judged here: the command must be git for-each-ref, log, branch or
    tag, and the parentheses must balance."""
    if head.text != 'git' or not w.text.startswith('--format=')             or w.unq.count('(') != w.unq.count(')')             or not any(a.text in ('for-each-ref', 'log', 'branch', 'tag') for a in args):
        raise Reject('parentheses outside a git --format= word')


def _check_segment(seg, cwd, prev_op=None, next_op=None, writes_safe=False,
                   cd_chained=False):
    """Judge one simple command. Returns (cwd for the next command, label).
    prev_op and next_op are the operators around it (see _segments).
    cd_chained: the previous command is a tracked cd and prev_op is '&&'."""
    words = [x for x in seg if isinstance(x, _Word)]
    redirs = [x for x in seg if isinstance(x, _Redir)]
    if not words:
        raise Reject('redirection without a command')
    head, args = words[0], words[1:]
    for w in words:
        # Any unquoted brace can expand ({d..d} is -delete, {o..o}utput is --output).
        if re.search(r'[{}]', w.unq):
            raise Reject('unquoted brace (brace expansion)')
        if '(' in w.unq or ')' in w.unq:
            _format_parens_ok(head, args, w)
        _glob_check(w, cwd)
    _check_head(head)
    for r in redirs:
        if r.target.status:
            raise Reject('$? in a redirect target')
        _check_redirect(r, cwd)
    name = head.text
    # $? is a number of unknown value: it may be printed, but never named as a
    # command or a path (COM$? could become COM1).
    if head.status or (any(w.status for w in args) and name not in STATUS_OK_HEADS):
        raise Reject('$? outside echo and printf')
    if name != 'curl' and any(URL_RE.search(w.text) for w in words):
        raise Reject('URL argument (network access)')
    if name == 'cd':
        # A cd runs only when the command before it succeeded, so it is tracked
        # after ';' or a newline, or after '&&' when the cd before it was tracked.
        if prev_op not in (None, ';', '\n') and not cd_chained:
            raise Reject('cd after && || or | cannot be tracked')
        if next_op not in (None, ';', '\n', '&&'):
            raise Reject('cd followed by | or || cannot be tracked')
        return _cd(args, cwd), 'cd'
    if _is_path_head(name):
        p = resolve_path(name, cwd)
        if p is None or not _is_inside(p, PROJECT) or _in_safe_scratch_or_build(p) \
                or not ntpath.normcase(p).endswith('\\.venv\\scripts\\python.exe'):
            raise Reject('path-like command name')
        rest = args[1:] if args and args[0].text == '-B' else args
        if not (len(rest) >= 2 and rest[0].text == '-m' and rest[1].text == 'pytest'
                and not rest[0].glob and not rest[1].glob):
            raise Reject('project venv python only as python -m pytest')
        _pytest(rest[2:], cwd)
        return cwd, 'pytest'
    if name in DENIED_HEADS or name not in ALLOWED_HEADS:
        raise Reject(f'command not allowed: {name}')
    _run_head(name, args, cwd, writes_safe)
    return cwd, name


def _segments_head_text(seg):
    for x in seg:
        if isinstance(x, _Word):
            return x.text
    return ''


def _seg_io(seg, cwd):
    """The files one simple command reads and writes, for the chain-wide loop
    rule. Reads are ('path', abs) or ('glob', native pattern); writes are abs
    paths. cd and the no-file heads read nothing, and fd duplications write
    nothing. Output to /dev/null is not a file."""
    words = [x for x in seg if isinstance(x, _Word)]
    redirs = [x for x in seg if isinstance(x, _Redir)]
    reads, writes = [], []
    head = words[0].text if words else ''
    if head not in ('cd',) and head not in NO_READ_HEADS:
        for w in words[1:]:
            if w.glob:
                pat = _pattern_path(w.text, cwd)
                if pat is not None:
                    reads.append(('glob', pat))
            else:
                p = resolve_path(w.text, cwd)
                if p is not None and p != DEVNULL:
                    reads.append(('path', p))
    for r in redirs:
        if r.op == '<':
            if not r.target.glob and r.target.text != DEVNULL:
                p = resolve_path(r.target.text, cwd)
                if p is not None and p != DEVNULL:
                    reads.append(('path', p))
        elif r.op == '>&' and r.target.text in ('1', '2', '-'):
            continue
        elif r.target.text != DEVNULL:
            p = resolve_path(r.target.text, cwd)
            if p is not None and p != DEVNULL:
                writes.append(p)
    return reads, writes


def _check_chain_io(reads, writes):
    """Reject a chain in which a file is both read and written, or a folder that
    holds a written file is read. A write whose content comes back as an input
    (cat a >> b && cat b >> a, cat x | cat >> x, sed p f >> f) doubles the data
    on every pass and fills the disk; a folder read (grep -r . > build/o) reads
    the output it is writing. Reading a file after writing it in the same chain
    is declined too, which is the price of a simple, sound rule."""
    # Compare by _ident (each component folded, trailing dots dropped), so that
    # "x.txt." and x.txt are the same file, as they are on Windows.
    for w in writes:
        wk = _ident(w)
        for kind, r in reads:
            if kind == 'glob':
                pat = '\\'.join(_win_name(c) for c in _components(r))
                if fnmatchcase('\\'.join(wk), pat):
                    raise Reject('a glob reads a file or folder that is also written')
                continue
            rk = _ident(r)
            if wk[:len(rk)] == rk or rk[:len(wk)] == wk:
                raise Reject('a file or folder is both read and written in the chain')


# ---------------------------------------------------------------- assignments
# Round 7 (lead decision): a standalone NAME=VALUE or export NAME=VALUE statement
# is allowed, and the later commands of the same line may use the variable. The
# lexer leaves a placeholder for every $NAME outside ALLOWED_VARS; _substitute_seg
# swaps it for the value before any other check, so '$S/x' is judged as the path
# it names.
MAX_ASSIGNED_VARS = 8
ASSIGN_DENY_NAMES = frozenset(
    'PATH IFS HOME TEMP TMP TMPDIR PWD OLDPWD CDPATH ENV BASH_ENV SHELL SHELLOPTS '
    'BASHOPTS PS1 PS2 PS3 PS4 PROMPT_COMMAND LANG LANGUAGE HISTFILE HISTFILESIZE '
    'EDITOR VISUAL PAGER MANPATH GOPATH GOROOT HTTP_PROXY HTTPS_PROXY ALL_PROXY '
    'NO_PROXY COMSPEC SYSTEMROOT WINDIR '
    # beyond the lead's list: names bash, the Windows loader or the project's tools read
    'GLOBIGNORE FIGNORE HISTIGNORE HISTCONTROL POSIXLY_CORRECT TIMEFORMAT MAIL '
    'MAILPATH MAILCHECK HOSTFILE INPUTRC FUNCNEST TERM TZ TZDIR USERPROFILE APPDATA '
    'LOCALAPPDATA PATHEXT PSMODULEPATH USER LOGNAME HOSTNAME SHLVL LINENO RANDOM '
    'SECONDS UID EUID GROUPS REPLY OPTIND OPTARG OPTERR COLUMNS LINES TMOUT '
    'HOMEDRIVE HOMEPATH GZIP TAR_OPTIONS MANPAGER GREP_OPTIONS GREP_COLOR GREP_COLORS '
    'AWKPATH AWKLIBPATH NLSPATH LOCPATH TERMINFO WSLENV CYGWIN'.split())
ASSIGN_DENY_PREFIXES = (
    'LD_', 'DYLD_', 'LC_', 'GIT_', 'PYTHON', 'NODE_', 'NPM_', 'BASH_', 'CURL_',
    'SSL_', 'PERL', 'RUBY', 'JAVA_', 'MSYS', 'CLAUDE_',
    'PYTEST_', 'RIPGREP_', 'GRAPHIFY_', 'OPENSPEC_', 'XDG_', 'LESS', 'JQ_', 'COMP',
    'PROGRAM', 'COMMON', 'SYSTEM', 'SSH_', 'GCM_', 'GH_', 'PIP_', 'OPENSSL_', 'REQUESTS_',
    'EXECIGNORE', 'HISTTIME', 'HISTSIZE',
)
ASSIGN_PLAIN_RE = re.compile(r'[A-Za-z0-9_.:-]{1,64}')
ASSIGN_WORD_RE = re.compile(r'[A-Za-z_][A-Za-z0-9_]*=')
ASSIGN_BAD_VALUE_RE = re.compile(r'''[\s$`*?\[\]{}~;&|<>()!'"\x00-\x1f]''')
PATHLIKE_VAR_RE = re.compile('^\x02[A-Za-z_][A-Za-z0-9_]*\x03(?:/[A-Za-z0-9_./-]*)?$')


def _assignment(seg):
    """(name, word) when the segment is a standalone NAME=VALUE or export
    NAME=VALUE statement, else None. The NAME= part must be unquoted ('S=x' in
    quotes is a command name, not an assignment) and the segment must hold nothing
    else, redirects included."""
    if any(isinstance(x, _Redir) for x in seg):
        return None
    words = seg
    if len(words) == 2 and words[0].unq == 'export' and not words[0].vars:
        words = words[1:]
    if len(words) != 1:
        return None
    w = words[0]
    m = ASSIGN_WORD_RE.match(w.unq)
    if not m:
        return None
    return w.text[:m.end() - 1], w


def _assignment_value(name, w, cwd):
    """Judge the VALUE of a standalone assignment and return it. A value is a
    literal with no $, backtick, space, glob character, ~ or control character, at
    most 300 characters, and is either a read-safe path or a plain token with no
    slash. The name may not be one that the shell, a loader or a tool reads."""
    up = name.upper()
    if up in ASSIGN_DENY_NAMES or up.startswith(ASSIGN_DENY_PREFIXES):
        raise Reject(f'assignment to a protected variable name: {name}')
    if w.vars:
        raise Reject('assignment value uses a variable')
    value = w.text[len(name) + 1:]
    if w.glob or not value or len(value) > 300 or ASSIGN_BAD_VALUE_RE.search(value):
        raise Reject('assignment value is not a plain literal')
    if ASSIGN_PLAIN_RE.fullmatch(value):
        return value
    p = resolve_path(value, cwd)
    if p is None or p == DEVNULL or not _read_safe(p):
        raise Reject('assignment value is neither a plain token nor a read-safe path')
    return value


def _var_is_path(w, assigned, cwd):
    """A word that is only $NAME or $NAME/path, whose value names a folder or file
    (it holds a separator or exists), is a file operand of sed, awk or jq, not a
    piece of the program."""
    if not PATHLIKE_VAR_RE.match(w.text):
        return False
    end = w.text.index('\x03')
    value = assigned.get(w.text[1:end], '')
    if w.text[end + 1:end + 2] == '/' or '/' in value or '\\' in value:
        return True
    p = resolve_path(value, cwd)
    return p is not None and os.path.exists(p)


def _substitute_seg(seg, assigned, cwd):
    """Replace the placeholders of assigned variables by their values, before any
    other check. A variable that was not assigned earlier in this command rejects,
    as does one as the command name or inside a sed, awk or jq program."""
    head_name = None
    out = []
    for x in seg:
        w = x.target if isinstance(x, _Redir) else x
        if head_name is None and isinstance(x, _Word):
            if w.vars:
                raise Reject('variable as the command name')
            head_name = w.text
        elif w.vars and head_name in ('sed', 'awk', 'gawk', 'mawk', 'jq') \
                and not _var_is_path(w, assigned, cwd):
            raise Reject('variable inside a sed, awk or jq program')
        if w.vars:
            for n in w.vars:
                if n not in assigned:
                    raise Reject(f'variable not assigned in this command: {n}')
            t_out, u_out, pos = [], [], 0
            for m in PLACEHOLDER_RE.finditer(w.text):
                t_out.append(w.text[pos:m.start()])
                u_out.append(w.unq[pos:m.start()])
                v = assigned[m.group(1)]
                t_out.append(v)
                u_out.append('\x01' * len(v))
                pos = m.end()
            t_out.append(w.text[pos:])
            u_out.append(w.unq[pos:])
            nw = _Word(''.join(t_out), ''.join(u_out), w.glob, True, w.pwd, w.status)
            x = _Redir(x.op, x.fd, nw) if isinstance(x, _Redir) else nw
        out.append(x)
    return out


def _classify(command, cwd):
    if not isinstance(command, str):
        raise Reject('command is not text')
    if len(command) > MAX_COMMAND_LEN:
        raise Reject('command longer than 6000 characters')
    if CONTROL_RE.search(command):
        raise Reject('control character')
    text = command.strip()
    if not text:
        raise Reject('empty command')
    if cwd is None:
        start = PROJECT  # developer mode and the test table; the hook always sends a cwd
    else:
        # Round 7: a cwd that is not an absolute path (5, ["x"], "build") rejects.
        if not isinstance(cwd, str) or not re.match(
                r'^(?:[A-Za-z]:[\\/]|/[A-Za-z](?:/|$)|/cygdrive/[A-Za-z](?:/|$))', cwd):
            raise Reject('cwd is not an absolute path')
        start = resolve_path(cwd, PROJECT)
    if start is None or not _read_safe(start):
        raise Reject('starting directory outside read-safe places')
    toks = _lex(text, start)
    segs, ops = _segments(toks)
    uses_pwd = any(isinstance(x, _Word) and x.pwd for seg in segs for x in seg)
    if uses_pwd and any(_segments_head_text(seg) == 'cd' for seg in segs):
        raise Reject('PWD used together with cd')
    writes_safe = any(_seg_writes(seg) for seg in segs)
    cur, labels, all_reads, all_writes = start, [], [], []
    prev_tracked_cd = False
    assigned, prev_assign = {}, False
    for k, seg in enumerate(segs):
        prev_op = ops[k - 1] if k else None
        stmt = _assignment(seg)
        if stmt:
            if len(set(assigned) | {stmt[0]}) > MAX_ASSIGNED_VARS:
                raise Reject('too many assigned variables')
            # It must run whenever it is reached: not after || or a pipe or a
            # command that may fail (&&), and a ; && or newline must follow it.
            if not ((prev_op in (None, ';', '\n') or (prev_op == '&&' and prev_assign))
                    and ops[k] in (';', '\n', '&&')):
                raise Reject('assignment that is conditional, piped or last')
            assigned[stmt[0]] = _assignment_value(stmt[0], stmt[1], cur)
            prev_assign = True
            labels.append('assign')
            continue
        prev_assign = False
        seg = _substitute_seg(seg, assigned, cur)
        seg_reads, seg_writes = _seg_io(seg, cur)
        all_reads.extend(seg_reads)
        all_writes.extend(seg_writes)
        is_cd = _segments_head_text(seg) == 'cd'
        cd_chained = prev_op == '&&' and prev_tracked_cd
        cur, label = _check_segment(seg, cur, prev_op, ops[k], writes_safe, cd_chained)
        prev_tracked_cd = is_cd and (prev_op in (None, ';', '\n') or cd_chained)
        labels.append(label)
    _check_chain_io(all_reads, all_writes)
    heads = '|'.join(dict.fromkeys(labels))
    return 'ALLOW', f'auto-allow-safe: {heads} in project'


def classify(command, cwd=None):
    """Pure decision function. Returns ("ALLOW" | "PASS", reason)."""
    _WALK_TIME[0] = 0.0
    del _WALKED[:]
    _GLOB_CACHE.clear()
    try:
        return _classify(command, cwd)
    except Reject as exc:
        return 'PASS', f'rule: {exc}'
    except Exception as exc:  # the hook must never raise
        return 'PASS', f'internal: {type(exc).__name__}'


# ---------------------------------------------------------------- hook I/O
def _log(decision, reason, command):
    try:
        if not os.path.isdir(BUILD_DIR):
            return
        cmd = (command if isinstance(command, str) else '')[:160]
        cmd = cmd.replace('\r', ' ').replace('\n', '\\n')
        stamp = datetime.now().isoformat(timespec='seconds')
        log_path = os.path.join(BUILD_DIR, LOG_NAME)
        try:
            if os.path.getsize(log_path) > LOG_MAX_BYTES:
                os.replace(log_path, log_path + '.1')  # best effort; one old file kept
        except Exception:
            pass
        with open(log_path, 'a', encoding='utf-8') as fh:
            fh.write(f'{stamp} {decision} {reason} | {cmd}\n')
    except Exception:
        pass


def _hook_main():
    try:
        data = json.load(sys.stdin)
    except Exception:
        return 0
    try:
        if not isinstance(data, dict) or data.get('tool_name') != 'Bash':
            return 0
        tool_input = data.get('tool_input')
        command = tool_input.get('command') if isinstance(tool_input, dict) else None
        cwd = data.get('cwd')
        if cwd is None:
            # Round 7: the hook always has a cwd; classify(None) means PROJECT only
            # for developer mode and the tests, so a missing cwd must not do that.
            decision, reason = 'PASS', 'rule: no cwd in the hook input'
        else:
            decision, reason = classify(command, cwd)
        _log(decision, reason, command)
        if decision == 'ALLOW':
            out = {'hookSpecificOutput': {
                'hookEventName': 'PreToolUse',
                'permissionDecision': 'allow',
                'permissionDecisionReason': reason,
            }}
            sys.stdout.write(json.dumps(out) + '\n')
    except Exception:
        pass
    return 0


def main(argv=None):
    argv = sys.argv[1:] if argv is None else argv
    if not argv:
        return _hook_main()
    ap = argparse.ArgumentParser(description='auto-allow PreToolUse hook (developer mode)')
    ap.add_argument('--check', required=True, metavar='COMMAND')
    ap.add_argument('--cwd', default=None)
    ns = ap.parse_args(argv)
    decision, reason = classify(ns.check, ns.cwd)
    print(f'{decision} {reason}')
    return 0 if decision == 'ALLOW' else 1


if __name__ == '__main__':
    sys.exit(main())
