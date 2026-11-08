/**
 * Bee front-end runtime, as an ES module, for a site that bundles its own JavaScript.
 *
 *     import Bee from './vendor/bee.mjs';
 *     Bee.start();   // reads the page's {{ craft.bee.runtimeConfig() }}
 *
 * Copy this file and bee.js side by side. This is a wrapper rather than a second copy of the
 * runtime: bee.js still sets `window.Bee`, so `Bee.cartAdd(…)` from inline scripts keeps working,
 * and a page that also has the injected runtime ends up with one instance, not two.
 */
import './bee.js';

export default window.Bee;
