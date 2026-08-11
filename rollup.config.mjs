import { nodeResolve } from "@rollup/plugin-node-resolve";

export default {
  input: "js/vendor-src/libphonenumber-entry.js",
  output: {
    file: "js/vendor/libphonenumber.js",
    format: "es",
    generatedCode: "es2015",
    compact: true,
  },
  plugins: [nodeResolve({ browser: true })],
};
