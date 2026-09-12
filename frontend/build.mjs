import { build } from 'esbuild';
await build({entryPoints:['conversations.js'],bundle:true,minify:true,format:'iife',target:['chrome90','safari15'],outfile:'../public/assets/telegram-conversations.js',legalComments:'eof',define:{'process.env.NODE_ENV':'"production"',__VUE_OPTIONS_API__:'false',__VUE_PROD_DEVTOOLS__:'false',__VUE_PROD_HYDRATION_MISMATCH_DETAILS__:'false'}});
