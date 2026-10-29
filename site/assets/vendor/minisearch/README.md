# MiniSearch 7.2.0

The handbook's search runs on [MiniSearch](https://github.com/lucaong/minisearch), a maintained, dependency-free
full-text search library (MIT, see `LICENSE.txt`). `minisearch-7.2.0.js` is the package's own UMD build,
`dist/umd/index.js` from `minisearch@7.2.0` on npm, unchanged:

    sha256 03c27931eb603af1d3358579e9885eafdb6a2f5fa9929557b3e77d7e99d06d03

To upgrade: `npm pack minisearch@<version>`, copy `dist/umd/index.js` here under the new version's name, update the
`<script>` path in `tools/HandbookSite/SiteBuilder.php`, and run `composer site:handbook`.
