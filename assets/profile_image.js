/*
 * Profile picture editor (Edit profile page): choose a picture, then drag to
 * position it, zoom with the slider and rotate in quarter turns, with a live
 * round preview. On submit the chosen square is rendered at the stored size
 * (data-size) into the hidden `edited` field as a PNG, and the original file
 * is not uploaded. The server validates and re-encodes that PNG like any
 * upload (App\Subscriber\ProfileImage). Without JavaScript the file is
 * posted and the server takes the centred square.
 *
 * Keyboard: with the picture focused, arrow keys move it, + and - zoom.
 */

class ProfileImageEditor {
    constructor(root) {
        this.root = root;
        this.form = root.closest('form');
        // The form renders its hidden fields at its end, outside the editor's container.
        const scope = this.form ?? root;
        this.file = scope.querySelector('[data-profile-image-file]');
        this.data = scope.querySelector('[data-profile-image-data]');
        this.editor = root.querySelector('[data-profile-image-editor]');
        this.stageBox = root.querySelector('[data-profile-image-stage]');
        this.stage = this.stageBox.querySelector('canvas');
        this.preview = root.querySelector('[data-profile-image-preview]');
        this.zoomInput = root.querySelector('[data-profile-image-zoom]');
        this.current = root.querySelector('[data-profile-image-current]');
        this.size = Number(root.dataset.size) || 256;
        this.reset();

        this.file.addEventListener('change', () => this.choose());
        this.zoomInput.addEventListener('input', () => this.zoom(Number(this.zoomInput.value)));
        root.querySelectorAll('[data-profile-image-action]').forEach((button) => {
            button.addEventListener('click', () => this.act(button.dataset.profileImageAction));
        });
        this.stageBox.addEventListener('pointerdown', (event) => this.dragStart(event));
        this.stageBox.addEventListener('keydown', (event) => this.key(event));
        this.form?.addEventListener('submit', (event) => this.capture(event));
    }

    reset() {
        this.bitmap = null;
        this.rotation = 0;
        this.scale = 1;
        this.offsetX = 0;
        this.offsetY = 0;
    }

    async choose() {
        const chosen = this.file.files?.[0];
        this.reset();
        this.data.value = '';
        if (!chosen) {
            this.show(false);
            return;
        }
        try {
            this.bitmap = await this.decode(chosen);
        } catch {
            // Not something the browser can draw: post the file and let the server explain.
            this.show(false);
            return;
        }
        this.zoomInput.value = '1';
        this.show(true);
        this.draw();
    }

    show(editing) {
        this.editor.hidden = !editing;
        if (this.current) {
            this.current.hidden = editing;
        }
    }

    act(action) {
        if (!this.bitmap) return;
        if (action === 'rotate-left') this.rotation = (this.rotation + 270) % 360;
        if (action === 'rotate-right') this.rotation = (this.rotation + 90) % 360;
        if (action === 'reset') {
            this.rotation = 0;
            this.offsetX = 0;
            this.offsetY = 0;
            this.zoom(1);
            return;
        }
        this.clamp();
        this.draw();
    }

    zoom(value) {
        this.scale = Math.max(1, Math.min(4, value || 1));
        this.zoomInput.value = String(this.scale);
        this.clamp();
        this.draw();
    }

    dragStart(event) {
        if (!this.bitmap || event.button !== 0) return;
        event.preventDefault();
        this.stageBox.focus();
        this.stageBox.classList.add('is-dragging');
        const from = { x: event.clientX, y: event.clientY, offsetX: this.offsetX, offsetY: this.offsetY };
        // Canvas pixels per CSS pixel: the stage is drawn larger than it is shown.
        const ratio = this.stage.width / this.stage.getBoundingClientRect().width;
        const move = (e) => {
            this.offsetX = from.offsetX + (e.clientX - from.x) * ratio;
            this.offsetY = from.offsetY + (e.clientY - from.y) * ratio;
            this.clamp();
            this.draw();
        };
        const stop = () => {
            this.stageBox.classList.remove('is-dragging');
            window.removeEventListener('pointermove', move);
            window.removeEventListener('pointerup', stop);
            window.removeEventListener('pointercancel', stop);
        };
        window.addEventListener('pointermove', move);
        window.addEventListener('pointerup', stop);
        window.addEventListener('pointercancel', stop);
    }

    key(event) {
        if (!this.bitmap) return;
        const step = this.stage.width / 40;
        const moves = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] };
        if (moves[event.key]) {
            this.offsetX += moves[event.key][0];
            this.offsetY += moves[event.key][1];
            this.clamp();
            this.draw();
        } else if (event.key === '+' || event.key === '=') {
            this.zoom(this.scale + 0.1);
        } else if (event.key === '-') {
            this.zoom(this.scale - 0.1);
        } else {
            return;
        }
        event.preventDefault();
    }

    /** Size of the picture covering the square frame (no empty edges) at the current zoom. */
    drawnSize(frame) {
        const turned = this.rotation % 180 !== 0;
        const width = turned ? this.bitmap.height : this.bitmap.width;
        const height = turned ? this.bitmap.width : this.bitmap.height;
        const cover = Math.max(frame / width, frame / height) * this.scale;
        return [width * cover, height * cover];
    }

    /** Keep the frame inside the picture. */
    clamp() {
        if (!this.bitmap) return;
        const frame = this.stage.width;
        const [width, height] = this.drawnSize(frame);
        const slackX = Math.max(0, (width - frame) / 2);
        const slackY = Math.max(0, (height - frame) / 2);
        this.offsetX = Math.max(-slackX, Math.min(slackX, this.offsetX));
        this.offsetY = Math.max(-slackY, Math.min(slackY, this.offsetY));
    }

    draw() {
        if (!this.bitmap) return;
        this.paint(this.stage);
        this.paint(this.preview);
    }

    /** Stage, preview and the submitted square share one routine, so they cannot disagree. */
    paint(canvas) {
        const frame = canvas.width;
        const ratio = frame / this.stage.width;
        const context = canvas.getContext('2d');
        context.clearRect(0, 0, frame, frame);
        context.save();
        context.translate(frame / 2 + this.offsetX * ratio, frame / 2 + this.offsetY * ratio);
        context.rotate((this.rotation * Math.PI) / 180);
        const [width, height] = this.drawnSize(this.stage.width);
        const turned = this.rotation % 180 !== 0;
        const drawWidth = (turned ? height : width) * ratio;
        const drawHeight = (turned ? width : height) * ratio;
        context.imageSmoothingQuality = 'high';
        context.drawImage(this.bitmap, -drawWidth / 2, -drawHeight / 2, drawWidth, drawHeight);
        context.restore();
    }

    capture() {
        if (!this.bitmap) {
            this.data.value = '';
            return;
        }
        const canvas = document.createElement('canvas');
        canvas.width = this.size;
        canvas.height = this.size;
        this.paint(canvas);
        this.data.value = canvas.toDataURL('image/png');
        // The square is all the server needs; the original (often megabytes) stays here.
        this.file.value = '';
    }

    /** The picture, turned the way its EXIF says. */
    async decode(file) {
        if (typeof createImageBitmap === 'function') {
            try {
                return await createImageBitmap(file, { imageOrientation: 'from-image' });
            } catch {
                // Older browsers reject the options argument; fall back to an <img>.
            }
        }
        return new Promise((resolve, reject) => {
            const url = URL.createObjectURL(file);
            const image = new Image();
            image.onload = () => {
                URL.revokeObjectURL(url);
                resolve(image);
            };
            image.onerror = () => {
                URL.revokeObjectURL(url);
                reject(new Error('The picture could not be decoded.'));
            };
            image.src = url;
        });
    }
}

document.querySelectorAll('[data-profile-image]').forEach((root) => new ProfileImageEditor(root));
