// Revision 012-R1: supersedes the obsolete complete-OAuth-removal checks.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.resolve(__dirname, '../..');
const read = p => fs.readFileSync(path.join(root,p),'utf8');
const login = read('resources/views/auth/partials/login-card.blade.php');
const registration = read('resources/views/auth/register.blade.php');
const controller = read('app/Http/Controllers/Auth/GoogleAuthController.php');
const routes = read('routes/web.php');
test('only profile linking controls are absent; Google login and technical service retained', () => {
    const profile = read('resources/views/profile/show.blade.php');
    assert.doesNotMatch(profile, /auth\.google\.link|Link Google Account|google-account-link-title/);
    assert.match(profile, /Notification Preferences/);
    assert.match(profile, /Save Contact Settings/);
    assert.match(login, /Continue with Google/);
    assert.match(login, /route\('auth\.google\.redirect'\)/);
    for (const name of ['login','password.request','register']) assert.ok(login.includes(`route('${name}')`), name);
    assert.match(login, /Verify with OTP/);
    const settings = read('resources/views/administration/settings.blade.php');
    assert.match(settings, /Google Sign-In/);
    assert.match(settings, /Email Delivery/);
    assert.match(read('public/css/app.css'), /\.login-page \.login-google/);
});
test('Google registration and subject-based login retained without automatic linking', () => {
    assert.match(registration, /\$google \? 'google' : 'manual'/);
    assert.match(registration, /data-registration-type="EMPLOYEE"/);
    assert.match(registration, /data-registration-type="EXTERNAL"/);
    assert.match(registration, /Verify email before SAO review/);
    assert.match(controller, /OauthIdentity::query\(\)->where\('provider', 'google'\)->where\('subject'/);
    assert.match(controller, /GoogleRegistrationContext::class\)->remember/);
    assert.match(controller, /establishAuthenticatedSession\(\$request, \$user\)/);
    assert.doesNotMatch(controller, /function link\(|OauthIdentity::query\(\)->create|GOOGLE_ACCOUNT_LINKED|link Google in Account Settings/);
    assert.match(controller, /\$legacyLink !== null \|\| \$request->user\(\) !== null/);
    assert.match(controller, /session\(\)->pull\('google_link'\)/);
    assert.match(controller, /session\(\)->forget\('state'\)/);
    assert.match(read('app/Services/BorrowerRegistrationService.php'), /OauthIdentity::query\(\)->create/);
    assert.match(read('app/Http/Controllers/Auth/BorrowerRegistrationController.php'), /\$verification->send\(\$registration->user\)/);
    assert.match(read('app/Http/Requests/BorrowerRegistrationRequest.php'), /Rule::in\(\['manual', 'google'\]\)/);
});
test('restored password visibility toggle functions', () => {
    const password = {type:'password'};
    let click;
    const attrs = {};
    const toggle = {addEventListener(name,callback) {assert.equal(name,'click'); click=callback;},setAttribute(k,v) {attrs[k]=v;}};
    const script = login.match(/<script>([\s\S]*?)<\/script>/)[1];
    vm.runInNewContext(script,{document:{addEventListener(name,callback) {assert.equal(name,'DOMContentLoaded');callback();},querySelector(){return null;},getElementById(id){return id==='password'?password:toggle;}}});
    click(); assert.equal(password.type,'text'); assert.equal(attrs['aria-label'],'Hide password');
    click(); assert.equal(password.type,'password'); assert.equal(attrs['aria-label'],'Show password');
});
test('only linking route removed, Google and previous Stock Card routes retained', () => {
    assert.doesNotMatch(routes,/auth\.google\.link|\/profile\/google\/link/);
    for (const name of ['auth.google.redirect','auth.google.callback','login.store','register.store','password.request','password.email','password.reset','password.update','account.verification.show','inventory.stock-card.print','inventory.stock-card.export']) assert.ok(routes.includes(`->name('${name}')`),name);
    assert.match(read('config/services.php'),/env\('GOOGLE_CLIENT_ID'\)/);
    assert.match(read('config/services.php'),/env\('GOOGLE_CLIENT_SECRET'\)/);
    assert.ok(fs.existsSync(path.join(root,'app/Services/GoogleRegistrationContext.php')));
});
