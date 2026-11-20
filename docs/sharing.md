# Sharing a Bia session

Experimental, opt-in collaboration between two running Bia terminals. No Bia
server or Nostr account is required. Existing Nostr relays carry encrypted
messages; they are still servers, with their own policies and availability.

## Commands

```text
Host:   /share
Guest:  /join a7K2m9Q4x8Lp
Host:   ↑/↓ select Allow or Deny, then Enter
Either: /share status
Either: /nickname billy
Either: /share stop
```

To join directly from your shell, run `bia join a7K2m9Q4x8Lp`. It validates the
code and relays before opening Bia, skips local MCP server startup and
`.mcp.json` prompts, and starts the same nickname, lookup, and host-approval
flow as `/join`, without a model API key. Custom relays work too:
`bia join <code> wss://relay.example`. It requires an interactive terminal and
cannot be combined with `--json` or `--resume`. The invite is not added to Bia's
prompt history; your shell may keep the command in its own history.

`/share` requires a configured model API key and prints a fresh, case-sensitive
12-character code with ready-to-copy `bia join` and `/join` commands. Send it
privately. The host must stay online during lookup;
lookup takes up to 30 seconds and Esc cancels it. On a failed or canceled lookup,
`/share stop` leaves remote mode so you can retry. Old `bia1.…` invites still work.

The first `/share` or `/join` asks for your nickname before connecting if it is
missing. Enter saves it in your personal config, `sharing.nickname`; Esc cancels.
`/nickname billy` updates it, or `/nickname` opens the editor. A changed name takes
effect on the next connection: an approved participant's name never changes under
someone else. Config can also set it explicitly:

```php
'sharing' => ['nickname' => 'Billy'],
```

Nicknames start with a letter or number and are 1–32 characters: letters, numbers,
spaces, dots, apostrophes, underscores and hyphens. Controls, markup and multiline
text are refused. Names
are **unverified labels**, not unique accounts. The host sees “Billy wants to
join” with the access warning, fingerprint and the standard Allow/Deny list.
↑/↓ selects, Enter confirms, and Esc denies. Deny is selected initially, and
neither typing `y`, pasting text nor Tab can accidentally allow someone. The
picker waits until the current turn/plugin finishes and the editor is empty,
without replacing tool permission prompts, drafts or other pickers.

Compare the fingerprint through the channel where you sent the invite before
approving; the guest can see their own with `/share status`. The first requester
might not be your friend. Admission is pinned to the full authenticated public
key, never a nickname. Invites authorize requests, not access. Denial blocks that
key for this share; the guest must leave and request again to retry. A silent
pending request expires after two minutes. The manual `/share approve <fingerprint>`
command remains available for compatibility.

One colored row above the workspace/model status shows sharing state: green
connected, accent connecting/waiting/reconnecting, red disconnected (plain text
with `NO_COLOR`). Relay URLs and availability appear only on `/share status`.
No relay debug traffic, keys, invites or packets are logged automatically. The
conversation retains meaningful join/leave events and ordinary conversation
output, not ongoing connection diagnostics.

`/share wss://relay.example wss://another.example` selects up to three relays.
Defaults are `wss://relay.damus.io` and `wss://nos.lol`. No project config can turn
sharing on or choose a relay. For custom relays, the guest uses
`/join <code> wss://relay.example wss://another.example`; `/share` prints this
complete command. Short-code lookup uses only the relays chosen locally, never
redirects to endpoints from a discovery response, and needs at least one working
relay in common with the host. `ws://` is allowed only on loopback for local testing. No relay
credentials, NIP-42 authentication, or paid-relay flow are implemented.

The guest needs no model API key. Guest messages go to the host, attributed as
`[Billy · remote]`, and queue behind its current turn (up to eight messages,
8,000 bytes each). Live host messages are labeled `[Daud · host]` on both sides.
Remote input never dispatches local slash commands, resolves host image chips,
inherits earlier attachments for tool uploads, or answers a host permission prompt.
The attachment boundary survives a resumed turn; the host can explicitly attach
an image again. This does not hide earlier conversation images from the host's model.
A guest's `/help`, `/exit`,
`/share stop`, `/share status`, and `/nickname` act locally; other slash commands
are disabled while joined. The guest never executes received tools. Its display is not saved to its
local conversation or prompt history; the host persists accepted messages as part
of its ordinary session.

`/share stop` discards queued guest input and cancels an active guest-origin turn,
including any question or permission prompt waiting on the host. It cannot undo
mutations already performed. It leaves a host-origin turn alone.
The same applies when a peer stop or timeout is detected. A disconnected guest
stays in remote mode until `/share stop`, preventing its next message from
accidentally running against its own files. Creating or resuming another host
session, or exiting, revokes the share. Sharing is never restored on resume.

## What is shared

- On approval: the last 40 completed entries, then ordered live text, user
  messages, tool arguments/results with edit diffs, running tool output, and turn completion.
- Large individual messages/results are truncated to 8,000 UTF-8 bytes and tool
  arguments to 3,000 bytes. Edit display data (path and diff only) is bounded to
  4,000 encoded bytes and 120 diff entries; result text may be shortened further
  to fit the packet. The display labels truncation. Attachments, reasoning,
  system prompts, arbitrary result metadata, usage metadata, and the memory store
  are not exported directly.
- **Conversation and tool output may contain source code, paths, credentials,
  or private memory quoted by the agent.** There is no automatic secret-redaction
  guarantee. Approval grants the guest access to this output and the ability to
  cause tools to read more. Only use it with a trusted collaborator.
- An invite is a private capability to request admission. It is printed locally
  but is not sent to the model, stored in prompt history, or logged by the relay
  transport. Anyone who receives it can request access until it expires.

Stopping prevents future sharing. It cannot erase material a guest received.

## Wire protocol and limits

A code is 12 independently random letters/digits (`A–Z`, `a–z`, `0–9`), about
71 bits of entropy, not a user-chosen password. A domain-separated SHA-256 hash
is its public lookup tag. Until a guest is approved, the host broadcasts a fresh
signed ephemeral kind **21118** announcement every five seconds to a `p` tag
containing that hash. Announcements require no relay storage: both sides must be
online, and announcements stop on approval, revocation, or one-hour expiry.
A relay may replay a recent announcement after revocation, but the stopped host
cannot approve or deliver anything.

The announcement contains an encrypted `bia1.` descriptor: the ephemeral host
public key, random 256-bit admission secret, relay URLs, and expiration. It uses
libsodium's XChaCha20-Poly1305 AEAD with a fresh 24-byte nonce and a key derived
from the code via HKDF-SHA-256, with a different domain from the public lookup
hash. Associated data binds the format, lookup tag, and host key. Guests verify
the signature, age, expiration and host-key binding before requesting admission.
A short code never directly grants tool access or becomes the session's signing
key. Host and guest each generate fresh secp256k1 keys, kept only in memory.
Keys are not Nostr social identities.

**Short-code tradeoff:** a relay can observe the lookup hash and attempt offline
code guesses; roughly 71 bits is less than the old invite's 256-bit admission
secret. Expiration stops admission, not offline analysis of recorded ciphertext.
Anyone with the code can decrypt the descriptor or impersonate an announcement,
so comparing the guest fingerprint through a trusted channel before approval is
important. The code never encrypts conversation packets: those still use NIP-44
between the independently generated host and approved guest keys.

Conversation NIP-01 signed events use ephemeral kind **21117**, with a single recipient `p`
tag. Their content is **NIP-44 v2** authenticated encryption. Event IDs and BIP-340
signatures are verified before decryption. The receiver checks recipient, kind,
time window, protocol version, invitation secret, and the approved peer identity.
Relays see IP addresses, temporary public keys, recipient tags, sizes and timing;
this is not an anonymity protocol or NIP-59 gift wrapping. Ephemeral kind semantics
ask relays not to store events, but cannot force them to forget ciphertext.

Encrypted envelopes contain `v`, `token`, `type`, `nickname`, cumulative `ack`,
`seq`, and `records`. Types are `join`, `welcome`, `live`, and `stop`; pre-admission
`pending`/`denied` replies contain only the version, token, type and host nickname,
never conversation records. They let the guest see “Waiting for Daud to approve…”
or a denial without granting access. Missing or invalid names from older peers
fall back to Host/Guest. Names are pinned at the initial request/response and
never used to authorize messages. Each direction has one
ordered stream and a stop-and-wait delivery window. Duplicate relay deliveries
cannot execute a guest message twice during that share's lifetime. Messages are
batched and retried until acknowledged; a relay `OK` is not execution confirmation.
A host queue acknowledgment says the message was queued, not that its turn worked.
There is no durable reconnect after a process restart and no automatic replay of
uncertain work into a replacement session.

Relay connections retry every five seconds. A live peer silent for 90 seconds,
a one-hour invite expiry, or an outbound backlog over 2 MiB ends the share. Stop
notifications are best effort; silence detection handles a lost notification.
Every socket, input queue, frame, record batch, and history snapshot is bounded.
Subscription failures, authentication requirements, and rejected events mark a
relay unavailable. Multiple relays improve availability but are not a guarantee;
public relay acceptance can change. Idle signatures are reused for up to ten
seconds to keep crypto off the hot path where possible.

## Implementation and checks

`Bia\Share` owns short-code discovery, invites, crypto, WebSocket transport and ordered delivery.
`Terminal\Sharing` integrates commands and remote input; `TuiRenderer` exposes
semantic observation, not terminal frames. Playback uses the existing renderer
and Surface in fullscreen and inline modes. Transport-control notices are never
rebroadcast. Nothing connects until `/share` or `/join`.

The Nostr crypto dependency is `innis/nostr-core`; its explicit pure-PHP signer
and ECDH implementation require GMP and sodium, with Composer also requiring intl.
Its GMP secret-key operations are **not constant-time** and the library does not
claim an independent security audit. This initial implementation uses only
short-lived session keys, not valuable long-lived signing keys. This limitation
matters on hostile shared hosts; do not treat this experimental feature as an
audited high-assurance remote-access product. No homegrown cryptographic algorithm
is used. The static build configuration includes the required extensions.

The WebSocket client runs on Crab's cancellable Connector and event loop because
Crab's existing WebSocket session is server-only and its HTTP client does not
expose socket upgrades. There is no blocking HTTP client or helper daemon.

Run `composer test` with GMP and sodium enabled for the crypto/integration tests.
`tests/Fixtures/share-server.php` prints loopback relay and scripted
OpenAI-compatible URLs for real-terminal checks. It is a test fixture, not a
production relay. Crypto tests skip with a clear reason if those extensions are
missing; ordinary Bia startup and help do not initialize sharing.
