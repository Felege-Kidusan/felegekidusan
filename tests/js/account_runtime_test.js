'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.resolve(__dirname, '..', '..');
const source = fs.readFileSync(path.join(root, 'frontend/js/account.js'), 'utf8');
const markerA = 'a'.repeat(64);
const markerB = 'b'.repeat(64);
const csrfA = 'c'.repeat(64);
const csrfB = 'd'.repeat(64);

class FakeClassList {
    constructor() { this.values = new Set(); }
    toggle(name, force) {
        if (force === undefined) force = !this.values.has(name);
        if (force) this.values.add(name); else this.values.delete(name);
        return force;
    }
    add(name) { this.values.add(name); }
    remove(name) { this.values.delete(name); }
    contains(name) { return this.values.has(name); }
}

const elements = Object.create(null);
class FakeElement {
    constructor(id) {
        this.id = id;
        this.value = '';
        this.textContent = '';
        this.hidden = false;
        this.disabled = false;
        this.required = false;
        this.open = false;
        this.src = '';
        this.files = [];
        this.dataset = {};
        this.attributes = Object.create(null);
        this.listeners = Object.create(null);
        this.classList = new FakeClassList();
        this.validity = { valid: true };
    }
    addEventListener(type, listener) {
        (this.listeners[type] || (this.listeners[type] = [])).push(listener);
    }
    dispatch(type, extra) {
        const event = Object.assign({ type, target: this, preventDefault() {} }, extra || {});
        for (const listener of this.listeners[type] || []) listener(event);
        return event;
    }
    setAttribute(name, value) {
        this.attributes[name] = String(value);
        if (name === 'open') this.open = true;
    }
    getAttribute(name) { return this.attributes[name] || ''; }
    removeAttribute(name) {
        delete this.attributes[name];
        if (name === 'open') this.open = false;
        if (name === 'src') this.src = '';
    }
    focus() { document.activeElement = this; }
    click() { this.dispatch('click'); }
    showModal() { this.open = true; }
    close() {
        const wasOpen = this.open;
        this.open = false;
        if (wasOpen) this.dispatch('close');
    }
    reset() {
        if (this.id === 'passwordForm') {
            for (const id of ['passwordCurrent', 'passwordNew', 'passwordConfirm']) element(id).value = '';
        }
    }
    querySelector(selector) {
        if (selector === '[aria-invalid="true"]') {
            return Object.values(elements).find((node) => node.attributes['aria-invalid'] === 'true') || null;
        }
        return null;
    }
}
function element(id) {
    if (!elements[id]) elements[id] = new FakeElement(id);
    return elements[id];
}

const ids = [
    'accountApp', 'accountOffline', 'accountGlobalAlert', 'accountAnnouncer',
    'accountHeaderInitials', 'accountHeaderName', 'profileImage', 'profileInitials',
    'summaryName', 'summaryUsername', 'summaryRole', 'summaryStatus', 'summaryEmail',
    'summaryCreated', 'summaryLastLogin', 'accountStatusDot', 'profileImageInput',
    'chooseImageButton', 'chooseImageText', 'removeImageButton', 'imageStatus',
    'profileForm', 'profileFieldset', 'profileFullName', 'profileUsername', 'profileEmail',
    'profileCurrentPassword', 'profilePasswordRequired', 'identityPasswordGroup',
    'profileFormAlert', 'profileDraftState', 'saveProfileButton', 'resetProfileButton',
    'openPasswordButton', 'imageConfirmDialog', 'imageUploadForm', 'imagePreview',
    'imageDialogError', 'confirmImageButton', 'removeImageDialog', 'removeImageError',
    'confirmRemoveImageButton', 'passwordDialog', 'passwordForm', 'passwordCurrent',
    'passwordNew', 'passwordConfirm', 'passwordCurrentError', 'passwordNewError',
    'passwordConfirmError', 'passwordFormAlert', 'passwordCharacterCount',
    'passwordByteCount', 'savePasswordButton', 'profileFullNameError',
    'profileUsernameError', 'profileEmailError', 'profileCurrentPasswordError'
];
ids.forEach(element);
element('accountApp').dataset.profileApi = '/admin/api_settings.php';
element('accountApp').dataset.profileImageUrl = '/admin/profile_image.php';
const meta = new FakeElement('csrf-meta');
meta.setAttribute('content', csrfA);

const documentListeners = Object.create(null);
const document = {
    readyState: 'complete',
    visibilityState: 'visible',
    activeElement: element('accountApp'),
    getElementById: element,
    querySelector(selector) {
        if (selector === 'meta[name="csrf-token"]') return meta;
        return null;
    },
    querySelectorAll(selector) {
        if (selector === '[data-mutation]') {
            return [
                element('chooseImageButton'), element('removeImageButton'), element('saveProfileButton'),
                element('openPasswordButton'), element('confirmImageButton'),
                element('confirmRemoveImageButton'), element('savePasswordButton')
            ];
        }
        return [];
    },
    addEventListener(type, listener) {
        (documentListeners[type] || (documentListeners[type] = [])).push(listener);
    }
};

class FakeFormData {
    constructor() { this.values = new Map(); }
    append(key, value) { this.values.set(key, value); }
    has(key) { return this.values.has(key); }
}

const revokedUrls = [];
let blobCounter = 0;
class TestURL extends URL {}
TestURL.createObjectURL = () => `blob:test-${++blobCounter}`;
TestURL.revokeObjectURL = (url) => revokedUrls.push(url);

let nextImageDimensions = { width: 64, height: 64, fail: false };
class FakeImage {
    constructor() {
        this.onload = null;
        this.onerror = null;
        this.naturalWidth = 0;
        this.naturalHeight = 0;
    }
    set src(value) {
        this._src = value;
        const dimensions = nextImageDimensions;
        queueMicrotask(() => {
            if (dimensions.fail) {
                if (this.onerror) this.onerror();
            } else {
                this.naturalWidth = dimensions.width;
                this.naturalHeight = dimensions.height;
                if (this.onload) this.onload();
            }
        });
    }
    get src() { return this._src; }
}

class FakeBroadcastChannel {
    constructor(name) { this.name = name; this.listeners = []; this.messages = []; }
    addEventListener(type, listener) { if (type === 'message') this.listeners.push(listener); }
    postMessage(message) { this.messages.push(message); }
    close() {}
}

function response(status, payload, headers) {
    const values = headers || {};
    return {
        ok: status >= 200 && status < 300,
        status,
        headers: { get(name) { return values[name] || null; } },
        async json() { return payload; }
    };
}

const fetchPlans = [];
const fetchCalls = [];
function planResponse(status, payload, headers) {
    fetchPlans.push(() => Promise.resolve(response(status, payload, headers)));
}
function planDeferred() {
    let resolve;
    const promise = new Promise((done) => { resolve = done; });
    fetchPlans.push(() => promise);
    return { resolve(status, payload, headers) { resolve(response(status, payload, headers)); } };
}
async function fetchMock(url, init) {
    fetchCalls.push({ url, init });
    const plan = fetchPlans.shift();
    if (!plan) throw new Error(`Unexpected fetch: ${url}`);
    return plan();
}

function profile(name, username, email, version) {
    return {
        id: 999,
        full_name: name,
        username,
        email,
        role: 'teacher',
        is_active: true,
        created_at: '2025-01-01 00:00:00',
        last_login: null,
        profile_version: version,
        profile_image: { present: false, version: null, url: null }
    };
}
function profilePayload(marker, user, csrf) {
    return {
        status: 'success',
        account_context: marker,
        csrf_token: csrf,
        user,
        login_count: 1
    };
}
function event() { return { preventDefault() {} }; }
async function flush() {
    for (let index = 0; index < 5; index += 1) await Promise.resolve();
    await new Promise((resolve) => setImmediate(resolve));
}
async function waitFor(predicate, description) {
    for (let index = 0; index < 50; index += 1) {
        if (predicate()) return;
        await new Promise((resolve) => setTimeout(resolve, 0));
    }
    assert.fail(`Timed out waiting for ${description}`);
}

let hooks;
const windowListeners = Object.create(null);
const windowObject = {
    APP: { csrf: csrfA, user: { id: 999, name: 'Account A', username: 'account.a', initials: 'AA' } },
    location: { origin: 'https://ssms.test' },
    setTimeout,
    clearTimeout,
    addEventListener(type, listener) {
        (windowListeners[type] || (windowListeners[type] = [])).push(listener);
    },
    BroadcastChannel: FakeBroadcastChannel,
    __SSMS_ACCOUNT_TEST_HOOK__(value) { hooks = value; }
};
windowObject.window = windowObject;

planResponse(200, profilePayload(markerA, profile('Account A', 'account.a', 'a@example.test', 'v-a'), csrfA));
const sandbox = {
    window: windowObject,
    document,
    navigator: { onLine: true },
    fetch: fetchMock,
    FormData: FakeFormData,
    Image: FakeImage,
    URL: TestURL,
    TextEncoder,
    Intl,
    Number,
    Promise,
    setTimeout,
    clearTimeout,
    queueMicrotask,
    console
};
vm.runInNewContext(source, sandbox, { filename: 'account.js' });

(async () => {
    await flush();
    assert(hooks, 'Account test hooks were not exposed');
    assert.strictEqual(hooks.state.accountContext, markerA);
    assert.strictEqual(element('profileFullName').value, 'Account A');
    assert.strictEqual(meta.getAttribute('content'), csrfA);

    // A deferred background response must not overwrite edits made after dispatch.
    const deferredRefresh = planDeferred();
    const refreshPromise = hooks.loadProfile({ preserveDraft: false });
    element('profileFullName').value = 'New draft after dispatch';
    element('profileFullName').dispatch('input');
    element('profileCurrentPassword').value = 'account-a-secret';
    element('profileCurrentPassword').dispatch('input');
    deferredRefresh.resolve(200, profilePayload(
        markerA,
        profile('Server Updated A', 'account.a', 'a@example.test', 'v-a2'),
        csrfA
    ));
    await refreshPromise;
    assert.strictEqual(hooks.state.canonical.full_name, 'Server Updated A');
    assert.strictEqual(element('profileFullName').value, 'New draft after dispatch');
    assert.strictEqual(element('profileCurrentPassword').value, 'account-a-secret');

    // Duplicate username/email responses map inline and never trigger a reload.
    element('profileFullName').value = hooks.state.canonical.full_name;
    element('profileUsername').value = 'taken.user';
    element('profileEmail').value = hooks.state.canonical.email;
    element('profileCurrentPassword').value = 'correct-current-password';
    const beforeUsernameCalls = fetchCalls.length;
    planResponse(409, { status: 'error', code: 'USERNAME_TAKEN', reason: 'USERNAME_TAKEN' });
    await hooks.submitProfile(event());
    assert.strictEqual(fetchCalls.length, beforeUsernameCalls + 1);
    assert.match(element('profileUsernameError').textContent, /already in use/);

    element('profileUsername').value = hooks.state.canonical.username;
    element('profileEmail').value = 'taken@example.test';
    element('profileCurrentPassword').value = 'correct-current-password';
    const beforeEmailCalls = fetchCalls.length;
    planResponse(409, { status: 'error', code: 'EMAIL_TAKEN', reason: 'EMAIL_TAKEN' });
    await hooks.submitProfile(event());
    assert.strictEqual(fetchCalls.length, beforeEmailCalls + 1);
    assert.match(element('profileEmailError').textContent, /already in use/);

    // A real conflict reloads canonical state while retaining the draft.
    element('profileUsername').value = hooks.state.canonical.username;
    element('profileEmail').value = hooks.state.canonical.email;
    element('profileCurrentPassword').value = '';
    element('profileFullName').value = 'Conflict draft';
    planResponse(409, { status: 'error', code: 'PROFILE_CONFLICT', reason: 'PROFILE_CONFLICT' });
    planResponse(200, profilePayload(
        markerA,
        profile('Canonical after conflict', 'account.a', 'a@example.test', 'v-a3'),
        csrfA
    ));
    await hooks.submitProfile(event());
    assert.strictEqual(hooks.state.canonical.full_name, 'Canonical after conflict');
    assert.strictEqual(element('profileFullName').value, 'Conflict draft');
    assert.match(element('profileFormAlert').textContent, /draft was preserved/);

    // Context mismatch invalidates the first response, clears all account-A
    // transient state, revokes object URLs, then accepts only a fresh B fetch.
    element('profileCurrentPassword').value = 'profile-secret-a';
    element('passwordCurrent').value = 'password-secret-a';
    element('passwordNew').value = 'new-secret-a-value';
    element('passwordConfirm').value = 'new-secret-a-value';
    hooks.state.pendingImage = { type: 'image/png', size: 100 };
    hooks.state.pendingPreviewUrl = 'blob:pending-a';
    hooks.state.visiblePreviewUrl = 'blob:visible-a';
    element('imageConfirmDialog').open = true;
    element('passwordDialog').open = true;
    planResponse(200, profilePayload(
        markerB,
        profile('Must Not Apply', 'account.b', 'b@example.test', 'v-b-old'),
        csrfB
    ));
    planResponse(200, profilePayload(
        markerB,
        profile('Fresh Account B', 'account.b', 'b@example.test', 'v-b'),
        csrfB
    ));
    await hooks.loadProfile({ preserveDraft: true });
    await waitFor(
        () => hooks.state.accountContext === markerB && hooks.state.canonical !== null,
        'the fresh account-B profile'
    );
    assert.strictEqual(hooks.state.accountContext, markerB);
    assert.strictEqual(hooks.state.canonical.full_name, 'Fresh Account B');
    assert.strictEqual(element('profileFullName').value, 'Fresh Account B');
    for (const id of ['profileCurrentPassword', 'passwordCurrent', 'passwordNew', 'passwordConfirm']) {
        assert.strictEqual(element(id).value, '', `${id} survived account switch`);
    }
    assert.strictEqual(hooks.state.pendingImage, null);
    assert.strictEqual(hooks.state.pendingPreviewUrl, null);
    assert.strictEqual(element('imageConfirmDialog').open, false);
    assert.strictEqual(element('passwordDialog').open, false);
    assert(revokedUrls.includes('blob:pending-a'));
    assert(revokedUrls.includes('blob:visible-a'));
    assert.strictEqual(meta.getAttribute('content'), csrfB);

    // A 401 clears canonical state, all secrets, pending images, and blob URLs.
    element('profileFullName').value = 'Sensitive draft B';
    element('profileCurrentPassword').value = 'profile-secret-b';
    element('passwordCurrent').value = 'password-secret-b';
    element('passwordNew').value = 'new-secret-b-value';
    element('passwordConfirm').value = 'new-secret-b-value';
    hooks.state.pendingImage = { type: 'image/png', size: 100 };
    hooks.state.pendingPreviewUrl = 'blob:pending-b';
    hooks.state.visiblePreviewUrl = 'blob:visible-b';
    planResponse(401, { status: 'error', code: 'AUTHENTICATION_REQUIRED' });
    await hooks.loadProfile({ preserveDraft: true });
    assert.strictEqual(hooks.state.canonical, null);
    assert.strictEqual(hooks.state.accountContext, null);
    assert.strictEqual(meta.getAttribute('content'), '');
    assert.strictEqual(windowObject.APP.csrf, '');
    for (const id of [
        'profileFullName', 'profileUsername', 'profileEmail', 'profileCurrentPassword',
        'passwordCurrent', 'passwordNew', 'passwordConfirm'
    ]) assert.strictEqual(element(id).value, '', `${id} survived 401`);
    assert.strictEqual(hooks.state.pendingImage, null);
    assert(revokedUrls.includes('blob:pending-b'));
    assert(revokedUrls.includes('blob:visible-b'));

    // Re-establish B, then prove duplicate password submission is locked.
    planResponse(200, profilePayload(
        markerB,
        profile('Fresh Account B', 'account.b', 'b@example.test', 'v-b'),
        csrfB
    ));
    await hooks.loadProfile({ preserveDraft: false });
    element('passwordCurrent').value = 'current-password-b';
    element('passwordNew').value = 'correct horse battery';
    element('passwordConfirm').value = 'correct horse battery';
    const deferredPassword = planDeferred();
    const beforePasswordCalls = fetchCalls.length;
    const firstPasswordSubmit = hooks.submitPassword(event());
    const secondPasswordSubmit = hooks.submitPassword(event());
    assert.strictEqual(fetchCalls.length, beforePasswordCalls + 1, 'mutation lock allowed duplicate submit');
    assert.strictEqual(fetchCalls.at(-1).init.headers['X-Account-Context'], markerB);
    element('passwordCurrent').value = 'newer-current-entry';
    element('passwordNew').value = 'newer password entry';
    element('passwordConfirm').value = 'newer password entry';
    element('passwordNew').dispatch('input');
    deferredPassword.resolve(200, {
        status: 'success',
        message: 'Password changed successfully',
        account_context: markerB
    });
    await Promise.all([firstPasswordSubmit, secondPasswordSubmit]);
    assert.strictEqual(element('passwordCurrent').value, 'newer-current-entry');
    assert.strictEqual(element('passwordNew').value, 'newer password entry');
    assert.strictEqual(element('passwordConfirm').value, 'newer password entry');
    assert.match(element('passwordFormAlert').textContent, /left untouched/);

    // An unchanged password draft is cleared after a successful mutation.
    element('passwordCurrent').value = 'newer-current-entry';
    element('passwordNew').value = 'final password value';
    element('passwordConfirm').value = 'final password value';
    element('passwordNew').dispatch('input');
    planResponse(200, {
        status: 'success',
        message: 'Password changed successfully',
        account_context: markerB
    });
    await hooks.submitPassword(event());
    for (const id of ['passwordCurrent', 'passwordNew', 'passwordConfirm']) {
        assert.strictEqual(element(id).value, '', `${id} survived unchanged password success`);
    }

    // Client pixel boundary accepts exactly 12M and rejects the next test case.
    nextImageDimensions = { width: 4000, height: 3000, fail: false };
    const exactPreview = await hooks.inspectSelectedImage({ type: 'image/png', size: 100 });
    assert.match(exactPreview, /^blob:test-/);
    TestURL.revokeObjectURL(exactPreview);
    nextImageDimensions = { width: 4001, height: 3000, fail: false };
    await assert.rejects(
        hooks.inspectSelectedImage({ type: 'image/png', size: 100 }),
        /12 megapixels/
    );

    assert.strictEqual(fetchPlans.length, 0, 'unused fetch plan remains');
    process.stdout.write('account runtime behavior: ok\n');
})().catch((error) => {
    console.error(error && error.stack ? error.stack : error);
    process.exitCode = 1;
});
