#!/usr/bin/env node
/**
 * Fails the build on a control that bypasses the shared design library.
 *
 * WHY THIS EXISTS. There are no CSS files in any extension package — every
 * style comes from `@tracht-digital-solutions/tds-shared`. A `<button>` with
 * no `btn` class therefore has no padding, no radius and no 44px
 * coarse-pointer target, and an `<input>` with no `field` class is worse than
 * unstyled: Tailwind's preflight zeroes borders, so it renders INVISIBLE and
 * its label collides with its value.
 *
 * That is not hypothetical. Before this check, across the 14 extension
 * packages, 86 of 101 buttons and 105 of 121 text controls were bare — the
 * settings panels shipped with invisible inputs for months, because nothing
 * looked at them and CI runs type-check and build rather than tests.
 *
 * Deliberately a plain regex scan, not a parser: it has to stay dependency
 * free so it can run as one step in every extension's CI without an install
 * ordering problem, and the shapes it is looking for are simple.
 *
 * Usage: node scripts/lint-primitives.mjs [dir]   (default: cwd)
 */
import { readdirSync, readFileSync, statSync } from "node:fs";
import { join, relative } from "node:path";

const ROOT = process.argv[2] ?? process.cwd();
const SKIP = new Set(["node_modules", "dist", ".git", ".claude", "vendor"]);

/** Input types that legitimately carry no `field` class. */
const BARE_TYPES = new Set(["checkbox", "radio", "file", "hidden", "submit", "button", "range"]);

const files = [];
(function walk(dir) {
  for (const entry of readdirSync(dir)) {
    if (SKIP.has(entry)) continue;
    const p = join(dir, entry);
    if (statSync(p).isDirectory()) walk(p);
    else if (/\.(tsx|astro)$/.test(entry) && !/\.test\.tsx$/.test(entry)) files.push(p);
  }
})(ROOT);

const classOf = (tag) => {
  const m = tag.match(/class(?:Name)?\s*=\s*(?:"([^"]*)"|'([^']*)'|\{([^}]*)\})/);
  return m ? (m[1] ?? m[2] ?? m[3] ?? "") : "";
};

const findings = [];
for (const file of files) {
  const src = readFileSync(file, "utf8");
  for (const m of src.matchAll(/<(button|input|select|textarea)\b[^>]*>/gs)) {
    const [tag, el] = m;
    const cls = classOf(tag);
    const line = src.slice(0, m.index).split(/\r?\n/).length;
    const where = `${relative(ROOT, file).replace(/\\/g, "/")}:${line}`;

    if (el === "button") {
      // `.chip` is the library's own filter/tab control and carries its own
      // hover + focus treatment, so it is an accepted alternative to `.btn`.
      if (!/\b(btn|chip)\b/.test(cls)) findings.push(`${where}  <button> needs "btn btn-*" (or "chip")`);
    } else {
      const type = (tag.match(/type\s*=\s*"([^"]*)"/) ?? [])[1] ?? "text";
      if (BARE_TYPES.has(type)) continue;
      if (!/\bfield\b/.test(cls)) findings.push(`${where}  <${el}> needs "field-boxed" (or "field")`);
    }
  }
}

if (findings.length) {
  console.error(`\n${findings.length} control(s) bypass the shared primitives:\n`);
  for (const f of findings) console.error(`  ${f}`);
  console.error("\nSee tds-shared/styles/primitives.css. `.btn` carries the geometry and");
  console.error("`.btn-*` only the colour — BOTH classes are required.\n");
  process.exit(1);
}
console.log(`lint-primitives: ${files.length} file(s) clean`);
