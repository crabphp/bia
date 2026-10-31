# Bia benchmarks

Measure the CLI from this directory with the same PHP binary used in
production:

```sh
/usr/bin/time -f 'real=%e rss=%MKB' php bin/bia --help >/dev/null
/usr/bin/time -f 'real=%e rss=%MKB' php bin/bia doctor >/dev/null
```

The first development measurement on PHP 8.4.22 reports about 0.03 seconds for
`--help` and about 41 MB of resident memory. That is a startup baseline on one
host, not a general performance claim. Bare `php -r ''` uses about 38 MB on
the same host, so Bia's own startup cost is about 3 MB.

Shell tool calls run `/bin/sh -c`, not a login shell: on the same host one
`sh -lc true` took 62 ms and one `sh -c true` 1.4 ms (50 runs each, through
`Subprocess`, whose own overhead is about 1 ms a call). Model first-token time, tool completion,
total task time, token usage, and memory need separate matched trials.
