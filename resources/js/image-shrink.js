/**
 * Photo size standard (27-11-2026): every photo is brought to a standard size and kept under its limit
 * (1 MB for inspection and employee photos) in the browser before upload.
 *
 * - fitSize(): fit inside long × short (landscape long × short, portrait short × long), aspect kept, never enlarged.
 * - encodeUnder(): JPEG at quality 0.82, lowered to 0.5; still too big → 85 % smaller and again, until it fits.
 * - shrinkPhotoFile(): for a chosen JPG / PNG file (employee photo, ID proof image). A file already within the size
 *   and the limit is returned as it is (no second compression).
 */
export function fitSize(w, h, long, short) {
    const s = Math.min(1, long / Math.max(w, h), short / Math.min(w, h));
    return { w: Math.max(1, Math.round(w * s)), h: Math.max(1, Math.round(h * s)) };
}

const toBlob = (canvas, q) => new Promise((res) => canvas.toBlob(res, 'image/jpeg', q));

/** draw(ctx, w, h) paints the picture at the given size; returns { blob, w, h }. */
export async function encodeUnder(draw, w, h, maxBytes) {
    const canvas = document.createElement('canvas');
    let blob = null;
    for (let round = 0; round < 8; round++) {
        canvas.width = w; canvas.height = h;
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#ffffff'; // PNG transparency becomes white, not black
        ctx.fillRect(0, 0, w, h);
        draw(ctx, w, h);
        for (let q = 0.82; q >= 0.5; q -= 0.08) {
            blob = await toBlob(canvas, q);
            if (blob && blob.size <= maxBytes) return { blob, w, h };
        }
        if (Math.max(w, h) <= 480) break;
        w = Math.round(w * 0.85); h = Math.round(h * 0.85);
    }
    return { blob, w, h };
}

async function decode(file) {
    if (window.createImageBitmap) {
        try { return await createImageBitmap(file, { imageOrientation: 'from-image' }); } catch { /* older browser: below */ }
    }
    const url = URL.createObjectURL(file);
    try {
        const img = new Image();
        img.src = url;
        await img.decode();
        return img;
    } finally {
        URL.revokeObjectURL(url);
    }
}

/**
 * Returns { file, changed, w, h }. Throws when the file is not a readable picture.
 * opts: { long, short, maxKb }
 */
export async function shrinkPhotoFile(file, { long, short, maxKb }) {
    const maxBytes = Math.floor(maxKb * 1024 * 0.95); // a little room under the server limit
    const pic = await decode(file);
    const srcW = pic.width || pic.naturalWidth;
    const srcH = pic.height || pic.naturalHeight;
    const fit = fitSize(srcW, srcH, long, short);
    if (file.size <= maxBytes && fit.w === srcW && fit.h === srcH) {
        pic.close?.();
        return { file, changed: false, w: srcW, h: srcH };
    }
    const out = await encodeUnder((ctx, w, h) => ctx.drawImage(pic, 0, 0, w, h), fit.w, fit.h, maxBytes);
    pic.close?.();
    if (!out.blob) throw new Error('encode');
    const name = file.name.replace(/\.[^.]+$/, '') + '.jpg';
    return { file: new File([out.blob], name, { type: 'image/jpeg', lastModified: Date.now() }), changed: true, w: out.w, h: out.h };
}

/** Put a new file into an <input type=file>. False when the browser does not allow it. */
export function replaceInputFile(input, file) {
    try {
        const dt = new DataTransfer();
        dt.items.add(file);
        input.files = dt.files;
        return input.files[0] === file || input.files.length === 1;
    } catch {
        return false;
    }
}
