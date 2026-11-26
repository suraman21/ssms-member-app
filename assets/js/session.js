/**
 * Felege Kidusan Members Portal — WebCrypto Device Key & DPoP Helper.
 *
 * Replaces the prototype's session.js.
 * - Private key lives in IndexedDB (never in localStorage).
 * - Converts WebCrypto P1363 (64-byte r||s) signatures to ASN.1 DER for OpenSSL.
 * - Builds per-request DPoP proofs (with `ath` when provided by the PHP BFF).
 */
(function (global) {
    'use strict';

    const DB_NAME = 'fkss_member_device';
    const STORE_NAME = 'keys';
    const KEY_ID = 'primary_p256';

    function openDb() {
        return new Promise(function (resolve, reject) {
            const req = indexedDB.open(DB_NAME, 1);
            req.onupgradeneeded = function () {
                if (!req.result.objectStoreNames.contains(STORE_NAME)) {
                    req.result.createObjectStore(STORE_NAME);
                }
            };
            req.onsuccess = function () { resolve(req.result); };
            req.onerror = function () { reject(req.error); };
        });
    }

    function toBase64(bytes) {
        let bin = '';
        for (let i = 0; i < bytes.length; i++) {
            bin += String.fromCharCode(bytes[i]);
        }
        return btoa(bin);
    }

    function toBase64Url(bytes) {
        return toBase64(bytes).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    function fromBase64Url(str) {
        const padded = str.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((str.length + 3) % 4);
        const bin = atob(padded);
        const out = new Uint8Array(bin.length);
        for (let i = 0; i < bin.length; i++) {
            out[i] = bin.charCodeAt(i);
        }
        return out;
    }

    /**
     * Convert WebCrypto raw ECDSA (64-byte r||s) to ASN.1 DER for openssl_verify().
     */
    function rawToDer(raw) {
        const toInt = function (b) {
            let i = 0;
            while (i < b.length - 1 && b[i] === 0) i++;
            let v = b.slice(i);
            if (v[0] & 0x80) {
                const prefixed = new Uint8Array(v.length + 1);
                prefixed[0] = 0;
                prefixed.set(v, 1);
                v = prefixed;
            }
            return v;
        };
        const r = toInt(raw.slice(0, 32));
        const s = toInt(raw.slice(32, 64));
        const body = new Uint8Array(4 + r.length + s.length);
        body[0] = 0x02;
        body[1] = r.length;
        body.set(r, 2);
        body[2 + r.length] = 0x02;
        body[3 + r.length] = s.length;
        body.set(s, 4 + r.length);

        const der = new Uint8Array(2 + body.length);
        der[0] = 0x30;
        der[1] = body.length;
        der.set(body, 2);
        return der;
    }

    async function getOrCreateDeviceIdentity() {
        const db = await openDb();
        const existing = await new Promise(function (resolve, reject) {
            const tx = db.transaction(STORE_NAME, 'readonly');
            const req = tx.objectStore(STORE_NAME).get(KEY_ID);
            req.onsuccess = function () { resolve(req.result || null); };
            req.onerror = function () { reject(req.error); };
        });

        if (existing && existing.keyPair && existing.installationId) {
            return existing;
        }

        const keyPair = await crypto.subtle.generateKey(
            { name: 'ECDSA', namedCurve: 'P-256' },
            false,
            ['sign', 'verify']
        );
        const installationId = 'web-' + crypto.randomUUID();
        const record = { keyPair: keyPair, installationId: installationId };

        await new Promise(function (resolve, reject) {
            const tx = db.transaction(STORE_NAME, 'readwrite');
            const req = tx.objectStore(STORE_NAME).put(record, KEY_ID);
            req.onsuccess = function () { resolve(); };
            req.onerror = function () { reject(req.error); };
        });

        return record;
    }

    async function exportSpkiBase64(publicKey) {
        const spki = await crypto.subtle.exportKey('spki', publicKey);
        return toBase64(new Uint8Array(spki));
    }

    async function signChallenge(challengeBase64Url) {
        const identity = await getOrCreateDeviceIdentity();
        const bytes = fromBase64Url(challengeBase64Url);
        const rawSig = await crypto.subtle.sign(
            { name: 'ECDSA', hash: 'SHA-256' },
            identity.keyPair.privateKey,
            bytes
        );
        return toBase64(rawToDer(new Uint8Array(rawSig)));
    }

    async function createDpopProof(htm, htu, ath) {
        const identity = await getOrCreateDeviceIdentity();
        const publicJwk = await crypto.subtle.exportKey('jwk', identity.keyPair.publicKey);
        delete publicJwk.key_ops;
        delete publicJwk.ext;

        const header = { typ: 'dpop+jwt', alg: 'ES256', jwk: publicJwk };
        const payload = {
            jti: crypto.randomUUID(),
            htm: htm.toUpperCase(),
            htu: htu,
            iat: Math.floor(Date.now() / 1000)
        };
        if (ath) {
            payload.ath = ath;
        }

        const enc = new TextEncoder();
        const signingInput = toBase64Url(enc.encode(JSON.stringify(header))) + '.' +
            toBase64Url(enc.encode(JSON.stringify(payload)));

        const rawSig = await crypto.subtle.sign(
            { name: 'ECDSA', hash: 'SHA-256' },
            identity.keyPair.privateKey,
            enc.encode(signingInput)
        );
        const derSig = rawToDer(new Uint8Array(rawSig));
        return signingInput + '.' + toBase64Url(derSig);
    }

    global.FKSession = {
        getOrCreateDeviceIdentity: getOrCreateDeviceIdentity,
        exportSpkiBase64: exportSpkiBase64,
        signChallenge: signChallenge,
        createDpopProof: createDpopProof,
        rawToDer: rawToDer
    };
})(window);
