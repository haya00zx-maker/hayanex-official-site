"use strict";
// Loopback ONLY. This development TLS adapter is not a production reverse proxy.
const https = require("node:https"),
  http = require("node:http"),
  fs = require("node:fs"),
  path = require("node:path");
const dir = process.argv[2];
const config = JSON.parse(
  fs.readFileSync(path.join(dir, "gateway.json"), "utf8"),
);
const origin = "https://127.0.0.1:24443";
const server = https.createServer(
  {
    key: fs.readFileSync(path.join(dir, "tls.key")),
    cert: fs.readFileSync(path.join(dir, "tls.crt")),
    minVersion: "TLSv1.2",
  },
  (req, res) => {
    if (req.headers.host !== "127.0.0.1:24443") {
      res.writeHead(421);
      res.end();
      return;
    }
    const chunks = [];
    let bytes = 0,
      exceeded = false;
    req.on("data", (chunk) => {
      bytes += chunk.length;
      if (bytes > 32768) {
        exceeded = true;
        res.writeHead(413);
        res.end();
        req.destroy();
      } else chunks.push(chunk);
    });
    req.on("end", () => {
      if (exceeded) return;
      const headers = {
        ...req.headers,
        host: "127.0.0.1:8080",
        "x-hayanex-dev-tls": config.key,
        "content-length": String(bytes),
      };
      for (const h of [
        "transfer-encoding",
        "forwarded",
        "x-forwarded-for",
        "x-forwarded-proto",
        "x-forwarded-host",
        "connection",
      ])
        delete headers[h];
      const upstream = http.request(
        {
          host: "127.0.0.1",
          port: 18880,
          path: req.url,
          method: req.method,
          headers,
        },
        (reply) => {
          res.writeHead(reply.statusCode, reply.headers);
          reply.pipe(res);
        },
      );
      upstream.setTimeout(10000, () => upstream.destroy());
      upstream.on("error", () => {
        if (!res.headersSent)
          res.writeHead(503, { "cache-control": "no-store" });
        res.end("開発用バックエンドを利用できません。");
      });
      res.on("close", () => upstream.destroy());
      upstream.end(Buffer.concat(chunks));
    });
  },
);
server.listen(24443, "127.0.0.1", () =>
  console.log("READY: private loopback TLS gateway (no external service)"),
);
