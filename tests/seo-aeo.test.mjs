import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const html = readFileSync(new URL("../index.html", import.meta.url), "utf8");
const robots = readFileSync(new URL("../robots.txt", import.meta.url), "utf8");
const sitemap = readFileSync(new URL("../sitemap.xml", import.meta.url), "utf8");
const canonical = "https://laptops.dyplus.com.ng/";

function metaContent(attribute, value) {
  const escaped = value.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
  return html.match(new RegExp(`<meta ${attribute}="${escaped}" content="([^"]+)"`))?.[1] || "";
}

test("homepage metadata consistently uses the canonical laptop-rental domain", () => {
  assert.match(html, /<title>Laptop Rental in Lagos, Abuja &amp; Nigeria \| DY-PLUS<\/title>/);
  assert.match(html, /<meta name="description" content="[^"]*training, conferences, examinations and corporate events[^"]*">/);
  assert.match(html, /<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">/);
  assert.match(html, new RegExp(`<link rel="canonical" href="${canonical.replace(/[.]/g, "\\.")}">`));
  assert.equal(metaContent("property", "og:url"), canonical);
  assert.equal(metaContent("property", "og:type"), "website");
  assert.equal(metaContent("property", "og:locale"), "en_NG");
  assert.equal(metaContent("name", "twitter:card"), "summary");
  assert.match(metaContent("property", "og:image"), /^https:\/\/laptops\.dyplus\.com\.ng\//);
  assert.match(metaContent("name", "twitter:image"), /^https:\/\/laptops\.dyplus\.com\.ng\//);
  assert.doesNotMatch(html, /https:\/\/rentals\.dyplus\.com\.ng\//);
});

test("visible headings and copy cover the approved geographic and service intent", () => {
  assert.match(html, /<h1[^>]*>[\s\S]*Laptop Rental in Lagos, Abuja &amp; Nigeria[\s\S]*Training, Conferences, Examinations &amp; Events[\s\S]*<\/h1>/);
  assert.match(html, /short-term, bulk or corporate laptop rental in Lagos and Abuja/i);
  assert.match(html, /standard or high-performance laptops/);
});

test("structured data is valid and contains only supported schema types", () => {
  const source = html.match(/<script type="application\/ld\+json">([\s\S]*?)<\/script>/)?.[1] || "";
  const data = JSON.parse(source);
  assert.equal(data["@context"], "https://schema.org");
  assert.deepEqual(data["@graph"].map((item) => item["@type"]), ["Organization", "WebSite", "WebPage", "Service", "FAQPage"]);
  for (const item of data["@graph"]) {
    if (item.url) assert.equal(item.url, canonical);
  }
  const service = data["@graph"].find((item) => item["@type"] === "Service");
  assert.deepEqual(service.areaServed.map((area) => area.name), ["Lagos", "Abuja", "Nigeria"]);
  assert.doesNotMatch(source, /AggregateRating|Review|ratingValue|reviewCount|testimonial/i);
  assert.equal(data["@graph"].some((item) => item["@type"] === "BreadcrumbList"), false);
});

test("FAQ schema exactly mirrors seven visibly rendered questions and approved answers", () => {
  const source = html.match(/<script type="application\/ld\+json">([\s\S]*?)<\/script>/)?.[1] || "";
  const faq = JSON.parse(source)["@graph"].find((item) => item["@type"] === "FAQPage");
  const section = html.match(/<section class="faq-section"[\s\S]*?<\/section>/)?.[0] || "";
  const visibleItems = [...section.matchAll(/<details class="faq-item"><summary><span>([^<]+)<\/span><span class="faq-indicator" aria-hidden="true"><\/span><\/summary><div class="faq-answer"><p>([\s\S]*?)<\/p><\/div><\/details>/g)].map((match) => ({
    name: match[1],
    text: match[2].replace(/<[^>]+>/g, "").replace(/&amp;/g, "&").trim(),
  }));
  assert.doesNotMatch(section, /\shidden(?:\s|>)/);
  assert.equal((section.match(/<details class="faq-item">/g) || []).length, 7);
  assert.equal(faq.mainEntity.length, 7);
  assert.deepEqual(faq.mainEntity.map((item) => ({ name: item.name, text: item.acceptedAnswer.text })), visibleItems);
  for (const fact of ["5 laptops", "₦35,000 per day", "VAT is 7.5%", "billable working day", "subject to availability", "Lagos and Abuja"]) {
    assert.match(section, new RegExp(fact.replace(/[.*+?^${}()|[\]\\]/g, "\\$&"), "i"));
  }
});

test("robots allows public indexing and protects operational paths", () => {
  assert.match(robots, /^User-agent: \*$/m);
  assert.match(robots, /^Allow: \/$/m);
  for (const path of ["/api/", "/private/", "/tools/", "/tests/", "/docs/"]) assert.match(robots, new RegExp(`^Disallow: ${path.replaceAll("/", "\\/")}$`, "m"));
  assert.match(robots, /^Sitemap: https:\/\/laptops\.dyplus\.com\.ng\/sitemap\.xml$/m);
});

test("sitemap contains only the canonical indexable homepage", () => {
  assert.match(sitemap, /^<\?xml version="1\.0" encoding="UTF-8"\?>/);
  assert.match(sitemap, /<urlset xmlns="http:\/\/www\.sitemaps\.org\/schemas\/sitemap\/0\.9">/);
  const locations = [...sitemap.matchAll(/<loc>([^<]+)<\/loc>/g)].map((match) => match[1]);
  assert.deepEqual(locations, [canonical]);
  assert.doesNotMatch(sitemap, /\/api\/|\/private\/|\/tools\/|\/tests\/|\/docs\//);
});
