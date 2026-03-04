const esbuild = require('esbuild');
esbuild.build({
  entryPoints: ['ptp-app.jsx'],
  bundle: true, outfile: 'ptp-app.js', format: 'iife', globalName: 'PTPEngineApp',
  target: ['es2020'], jsx: 'automatic', jsxImportSource: 'react',
  plugins: [{
    name: 'react-to-wp',
    setup(build) {
      build.onResolve({ filter: /^react$/ }, () => ({ path: 'react', namespace: 'wp-react' }));
      build.onResolve({ filter: /^react\/jsx-runtime$/ }, () => ({ path: 'react/jsx-runtime', namespace: 'wp-jsx' }));
      build.onLoad({ filter: /.*/, namespace: 'wp-react' }, () => ({
        contents: `const R=window.wp?.element||window.React;export default R;export const useState=R.useState,useEffect=R.useEffect,useCallback=R.useCallback,useRef=R.useRef,useMemo=R.useMemo,useReducer=R.useReducer,createContext=R.createContext,useContext=R.useContext,Fragment=R.Fragment,createElement=R.createElement,Component=R.Component;`,
        loader: 'js',
      }));
      build.onLoad({ filter: /.*/, namespace: 'wp-jsx' }, () => ({
        contents: `const R=window.wp?.element||window.React;export function jsx(t,p,k){if(k!==undefined)p={...p,key:k};return R.createElement(t,p)}export function jsxs(t,p,k){if(k!==undefined)p={...p,key:k};return R.createElement(t,p)}export const Fragment=R.Fragment;`,
        loader: 'js',
      }));
    }
  }],
}).then(()=>{const s=require('fs').statSync('ptp-app.js');console.log(`Built: ${(s.size/1024).toFixed(1)}KB`)}).catch(e=>{console.error(e);process.exit(1)});
