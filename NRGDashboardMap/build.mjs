import * as esbuild from 'esbuild';

await esbuild.build({
  entryPoints: ['./entry.js'],
  bundle: true,
  outfile: './three.min.js',
  minify: true,
  target: 'es2020',
  external: [],
  platform: 'browser'
});

console.log('✅ three.min.js built (bundled Three.js)');
