/**
 * Run with the Browser skill's connected tab and viewport capability, after
 * ResponsiveUiTest exports SPMU_UI_SNAPSHOTS. Serve those fixtures at /snapshots
 * and the checkout's public directory at /. No production login is needed.
 *
 * const { auditResponsivePages } = await import('/absolute/path/to/this/file');
 * await auditResponsivePages({ tab, viewport, pages: manifest, widths: [320,375,768,1024,1280,1440] });
 */
export async function auditResponsivePages({ tab, viewport, pages, widths, baseUrl = 'http://127.0.0.1:8765' }) {
    if (!pages.length || !widths.length) throw new Error('Provide rendered fixtures and viewport widths');
    const results = [];
    for (const width of widths) {
        await viewport.set({ width, height: 900 });
        for (const page of pages) {
            await tab.goto(`${baseUrl}/snapshots/${page.file}`);
            await tab.playwright.waitForLoadState({ state: 'load' });
            const measure = () => {
                const root = document.documentElement;
                const contained = (element) => {
                    for (let p = element.parentElement; p && p !== document.body; p = p.parentElement) {
                        const style = getComputedStyle(p);
                        if (['auto', 'scroll'].includes(style.overflowX) && p.clientWidth < p.scrollWidth) return true;
                    }
                    return false;
                };
                const visible = (element) => element.getClientRects().length && getComputedStyle(element).visibility !== 'hidden';
                const overflow = [...document.querySelectorAll('main *')].filter(element => {
                    if (!visible(element) || contained(element)) return false;
                    const box = element.getBoundingClientRect();
                    return box.width > 0 && (box.right > root.clientWidth + 2 || box.left < -2);
                }).slice(0, 12).map(element => ({
                    tag: element.tagName, selector: element.id || element.className,
                    width: Math.round(element.getBoundingClientRect().width),
                }));
                const steps = [...document.querySelectorAll('.request-tracker__step')].map(element => {
                    const label = element.querySelector('strong');
                    const box = label.getBoundingClientRect();
                    return { label: label.textContent.trim(), width: Math.round(box.width), right: Math.round(box.right) };
                });
                const crampedHeadings = [...document.querySelectorAll('main h1, main h2')]
                    .filter(element => visible(element) && !element.closest('.visually-hidden, [hidden]') && element.textContent.trim().length > 12 && element.getBoundingClientRect().width < 80)
                    .map(element => ({ text: element.textContent.trim(), width: Math.round(element.getBoundingClientRect().width) }));
                return { title: document.title, viewport: root.clientWidth, scrollWidth: root.scrollWidth, overflow, steps, crampedHeadings };
            };
            const states = ['initial'];
            // A populated selected-items table becomes visible after returning
            // from Review. It must stay inside its scroller at every stage.
            if (page.file.includes('borrower-stage-3-')) states.push('back to stage 2', 'back to stage 1');
            for (const state of states) {
                if (state !== 'initial') {
                    await tab.playwright.getByRole('button', { name: 'Back', exact: true }).filter({ visible: true }).click();
                }
                const metrics = await tab.playwright.evaluate(measure);
                const failures = [];
                if (metrics.scrollWidth > metrics.viewport + 2) failures.push('page overflow');
                if (metrics.overflow.length) failures.push('uncontained content');
                if (metrics.steps.some(step => step.width < 60 && step.label.length > 12)) failures.push('cramped progress label');
                if (metrics.crampedHeadings.length) failures.push('cramped heading');
                results.push({ file: page.file, width, state, failures, ...metrics });
            }
        }
    }
    return results;
}
