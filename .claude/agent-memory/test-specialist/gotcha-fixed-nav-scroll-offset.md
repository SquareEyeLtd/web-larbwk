---
name: gotcha-fixed-nav-scroll-offset
description: Naive scrollTo(element.top) hides the element behind the site's fixed top nav; must subtract nav height first
metadata:
  type: project
---

The site's top nav (`.nav.affix`) is `position: fixed` and covers the top of the viewport at all scroll positions — about 142px tall at 1440x900, about 99px tall at 390x844 (confirmed 2026-09-09 on the flagship event single page, likely sitewide since the class is generic).

`getBoundingClientRect()` on a target element is unaffected by fixed-position siblings (it reports true viewport coordinates), so a naive `window.scrollTo(0, window.scrollY + target.getBoundingClientRect().top - 10)` will put the target at viewport y≈10, which is behind the fixed nav's solid background, not actually visible in a viewport screenshot. The resulting screenshot silently shows unrelated lower content instead (no error, just wrong).

**Why:** cost real time on the flagship timeline spacing check (2026-09-09) — first screenshot attempt looked plausible (no crash, no obvious wrongness) but was actually two rows further down the page than intended, missing the very heading being measured.

**How to apply:** before scrolling to screenshot any specific element, detect fixed/sticky elements first (`getComputedStyle(el).position === 'fixed'`) and subtract their height from the scroll target, e.g. `scrollTo(0, scrollY + target.top - navHeight - margin)`. Cheap one-off detection snippet:
```js
Array.from(document.querySelectorAll('*')).filter(e => {
  const cs = getComputedStyle(e);
  return (cs.position === 'fixed' || cs.position === 'sticky') && e.offsetHeight > 0;
})
```
Re-check the element's rect after scrolling and before screenshotting to confirm it actually landed clear of any fixed chrome.
