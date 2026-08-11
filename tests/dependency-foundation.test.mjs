import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const root = new URL("../", import.meta.url);
const read = async (path) => readFile(new URL(path, root), "utf8");

test("Composer locks the approved PHP libphonenumber dependency", async () => {
  const manifest = JSON.parse(await read("composer.json"));
  const lock = JSON.parse(await read("composer.lock"));
  assert.equal(manifest.require["giggsey/libphonenumber-for-php"], "^9.0");
  assert.ok(lock.packages.some(({ name }) => name === "giggsey/libphonenumber-for-php"));
});

test("npm locks the approved browser library and deterministic bundler", async () => {
  const manifest = JSON.parse(await read("package.json"));
  const lock = JSON.parse(await read("package-lock.json"));
  assert.equal(manifest.dependencies["libphonenumber-js"], "1.13.10");
  assert.equal(manifest.devDependencies.rollup, "4.62.4");
  assert.equal(manifest.devDependencies["@rollup/plugin-node-resolve"], "16.0.3");
  assert.equal(lock.packages["node_modules/libphonenumber-js"].version, "1.13.10");
  assert.equal(lock.packages["node_modules/rollup"].version, "4.62.4");
  assert.equal(lock.packages["node_modules/@rollup/plugin-node-resolve"].version, "16.0.3");
});

test("the browser build is local, deployable and has no runtime package fetch", async () => {
  const manifest = JSON.parse(await read("package.json"));
  const bundle = await read("js/vendor/libphonenumber.js");
  assert.equal(manifest.scripts.build, "rollup --config rollup.config.mjs");
  const config = await read("rollup.config.mjs");
  assert.match(config, /input: "js\/vendor-src\/libphonenumber-entry\.js"/);
  assert.match(config, /file: "js\/vendor\/libphonenumber\.js"/);
  assert.doesNotMatch(bundle, /from\s+["'](?:libphonenumber-js|https?:)/);
  assert.doesNotMatch(bundle, /\b(?:fetch|import)\s*\(\s*["']https?:/);
  assert.match(bundle, /parsePhoneNumberFromString/);
  assert.match(bundle, /getCountryCallingCode/);
});

test("the deployable bundle exposes libphonenumber metadata without changing the live workflow", async () => {
  const phoneLibrary = await import(new URL("../js/vendor/libphonenumber.js", import.meta.url));
  assert.equal(phoneLibrary.parsePhoneNumberFromString("020 7183 8750", "GB")?.number, "+442071838750");
  assert.ok(phoneLibrary.getCountries().includes("NG"));
  assert.equal(phoneLibrary.getCountryCallingCode("NG"), "234");
  const html = await read("index.html");
  assert.doesNotMatch(html, /js\/vendor\/libphonenumber\.js/);
});
