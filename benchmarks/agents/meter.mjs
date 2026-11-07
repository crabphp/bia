// A metering proxy: every agent talks to the same upstream through it, so
// requests, time and tokens are counted one way for all of them.
//
//   node meter.mjs <port> <upstream-origin-and-path> <log.jsonl>
//
// A request to /r/<run>/<rest> goes to <upstream>/<rest>. The agent's own
// key is dropped and BENCH_UPSTREAM_KEY is sent instead, so no agent home
// holds a real key. Usage is read from OpenAI Chat Completions, OpenAI
// Responses and Anthropic Messages answers, streamed or not.

import http from 'node:http';
import https from 'node:https';
import fs from 'node:fs';

const [port, upstream, logPath] = process.argv.slice(2);
const key = process.env.BENCH_UPSTREAM_KEY ?? '';
if (!port || !upstream || !logPath || key === '') {
  console.error('usage: BENCH_UPSTREAM_KEY=... node meter.mjs <port> <upstream> <log.jsonl>');
  process.exit(2);
}
const base = new URL(upstream.replace(/\/+$/, ''));
const client = base.protocol === 'https:' ? https : http;

const server = http.createServer((req, res) => {
  const match = /^\/r\/([^/]+)(\/.*)?$/.exec(req.url ?? '');
  if (!match) {
    res.writeHead(404).end('no run in path');
    return;
  }
  const run = match[1];
  const rest = match[2] ?? '/';
  const started = performance.now();
  const body = [];
  req.on('data', (chunk) => body.push(chunk));
  req.on('end', () => {
    const payload = Buffer.concat(body);
    let model = null;
    let stream = false;
    try {
      const json = JSON.parse(payload.toString('utf8'));
      model = json.model ?? null;
      stream = json.stream === true;
    } catch {}
    const headers = { ...req.headers };
    delete headers.host;
    delete headers['x-api-key'];
    delete headers['content-length'];
    headers.authorization = `Bearer ${key}`;
    headers['accept-encoding'] = 'identity';
    if (payload.length > 0) headers['content-length'] = String(payload.length);

    const target = new URL(base.pathname.replace(/\/$/, '') + rest, base);
    const out = client.request(target, { method: req.method, headers }, (up) => {
      let first = null;
      const chunks = [];
      res.writeHead(up.statusCode ?? 502, up.headers);
      up.on('data', (chunk) => {
        first ??= performance.now();
        chunks.push(chunk);
        res.write(chunk);
      });
      up.on('end', () => {
        res.end();
        const text = Buffer.concat(chunks).toString('utf8');
        record({
          run,
          at: new Date().toISOString(),
          method: req.method,
          path: rest,
          status: up.statusCode,
          model,
          stream,
          first_ms: first === null ? null : Math.round(first - started),
          total_ms: Math.round(performance.now() - started),
          request_bytes: payload.length,
          response_bytes: Buffer.byteLength(text),
          usage: usage(text),
        });
      });
    });
    out.on('error', (error) => {
      record({ run, at: new Date().toISOString(), method: req.method, path: rest, status: 0, model, error: String(error), total_ms: Math.round(performance.now() - started) });
      if (!res.headersSent) res.writeHead(502);
      res.end();
    });
    out.end(payload);
  });
});

function record(line) {
  fs.appendFileSync(logPath, JSON.stringify(line) + '\n');
}

/**
 * Normalized usage: input counts every prompt token, cached included;
 * cached is the part read from the cache; output includes reasoning.
 */
function usage(text) {
  const docs = [];
  if (/^\s*[{[]/.test(text)) {
    try { docs.push(JSON.parse(text)); } catch {}
  }
  for (const line of text.split('\n')) {
    if (!line.startsWith('data:')) continue;
    const data = line.slice(5).trim();
    if (data === '' || data === '[DONE]') continue;
    try { docs.push(JSON.parse(data)); } catch {}
  }
  let found = null;
  let anthropic = null;
  for (const doc of docs) {
    // Anthropic: message_start carries the input side, message_delta the output.
    if (doc.type === 'message_start' && doc.message?.usage) {
      anthropic = { ...doc.message.usage };
      continue;
    }
    if (doc.type === 'message_delta' && doc.usage) {
      anthropic = { ...(anthropic ?? {}), ...doc.usage };
      continue;
    }
    if (doc.type === 'message' && doc.usage) {
      anthropic = { ...doc.usage };
      continue;
    }
    const u = doc.response?.usage ?? doc.usage;
    if (u) found = u;
  }
  if (anthropic) {
    const cached = anthropic.cache_read_input_tokens ?? 0;
    const written = anthropic.cache_creation_input_tokens ?? 0;
    return {
      input: (anthropic.input_tokens ?? 0) + cached + written,
      cached,
      output: anthropic.output_tokens ?? 0,
      reasoning: anthropic.output_tokens_details?.thinking_tokens ?? null,
    };
  }
  if (!found) return null;
  if ('prompt_tokens' in found) {
    return {
      input: found.prompt_tokens ?? 0,
      cached: found.prompt_tokens_details?.cached_tokens ?? 0,
      output: found.completion_tokens ?? 0,
      reasoning: found.completion_tokens_details?.reasoning_tokens ?? null,
    };
  }
  return {
    input: found.input_tokens ?? 0,
    cached: found.input_tokens_details?.cached_tokens ?? 0,
    output: found.output_tokens ?? 0,
    reasoning: found.output_tokens_details?.reasoning_tokens ?? null,
  };
}

server.listen(Number(port), '127.0.0.1', () => {
  console.log(`meter ready on ${port}`);
});
