import { build } from 'esbuild';
const common={bundle:true,minify:true,format:'iife',target:['chrome90','safari15'],legalComments:'eof',define:{'process.env.NODE_ENV':'"production"',__VUE_OPTIONS_API__:'false',__VUE_PROD_DEVTOOLS__:'false',__VUE_PROD_HYDRATION_MISMATCH_DETAILS__:'false'}};
await build({...common,entryPoints:['conversations.js'],outfile:'../public/assets/telegram-conversations.js'});
await build({...common,entryPoints:['admin.js'],outfile:'../public/assets/admin-ui.js'});
// Keep the existing public stylesheet separate from the authenticated admin theme.
const {rename}=await import('node:fs/promises');
await rename('../public/assets/admin-ui.js','../public/assets/app.js');
