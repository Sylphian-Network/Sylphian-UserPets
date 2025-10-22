XF.PetRenderer = XF.Element.newHandler({
    options: {
        spriteSheetPath: null,
        states: {
            'idle': 0,
            'feed': 1,
            'play': 2,
            'sleep': 3,
        },
        frameWidth: 192,
        frameHeight: 192,
        fps: 4,
        framesPerAnimation: 4,
        scaleMin: 0.5,
        scaleMax: 1.0
    },

    canvas: null,
    context: null,
    spriteSheet: null,
    spriteSheetLoaded: false,
    currentState: 'idle',
    currentFrame: 0,
    animationInterval: null,

    petLevel: 1,
    targetScale: 0.5,
    currentScale: 0.5,
    scaleAnimationActive: false,

    /**
     * Initialize the pet renderer
     */
    init: function() {
        this.canvas = this.target;
        if (!this.canvas || !(this.canvas instanceof HTMLCanvasElement)) {
            console.error('PetRenderer: Target is not a canvas element');
            return;
        }

        this.context = this.canvas.getContext('2d');
        if (!this.context) {
            console.error('PetRenderer: Could not get 2D context');
            return;
        }

        this.canvas.width = 192;
        this.canvas.height = 192;

        const ds = this.canvas.dataset || {};
        const getNum = function (val, def, min, max) {
            let n = (val == null ? NaN : Number(val));
            if (!Number.isFinite(n)) n = def;
            if (min != null && Number.isFinite(min)) n = Math.max(min, n);
            if (max != null && Number.isFinite(max)) n = Math.min(max, n);
            return n;
        };

        // Sprite sheet path
        if (ds.spriteSheetPath) {
            this.options.spriteSheetPath = ds.spriteSheetPath;
        } else {
            const ssAttr = this.canvas.getAttribute('data-sprite-sheet-path');
            if (ssAttr) this.options.spriteSheetPath = ssAttr;
        }

        // Frame dimensions
        if (ds.frameWidth != null) {
            this.options.frameWidth = getNum(ds.frameWidth, this.options.frameWidth, 1);
        } else {
            const fwAttr = this.canvas.getAttribute('data-frame-width');
            if (fwAttr != null) this.options.frameWidth = getNum(fwAttr, this.options.frameWidth, 1);
        }
        if (ds.frameHeight != null) {
            this.options.frameHeight = getNum(ds.frameHeight, this.options.frameHeight, 1);
        } else {
            const fhAttr = this.canvas.getAttribute('data-frame-height');
            if (fhAttr != null) this.options.frameHeight = getNum(fhAttr, this.options.frameHeight, 1);
        }

        // Animation config
        if (ds.fps != null) {
            this.options.fps = getNum(ds.fps, this.options.fps, 1, 120);
        } else {
            const fpsAttr = this.canvas.getAttribute('data-fps');
            if (fpsAttr != null) this.options.fps = getNum(fpsAttr, this.options.fps, 1, 120);
        }
        if (ds.framesPerAnimation != null) {
            this.options.framesPerAnimation = getNum(ds.framesPerAnimation, this.options.framesPerAnimation, 1);
        } else {
            const fpaAttr = this.canvas.getAttribute('data-frames-per-animation');
            if (fpaAttr != null) this.options.framesPerAnimation = getNum(fpaAttr, this.options.framesPerAnimation, 1);
        }

        // Scaling bounds
        if (ds.scaleMin != null) {
            this.options.scaleMin = getNum(ds.scaleMin, this.options.scaleMin, 0.01);
        } else {
            const smin = this.canvas.getAttribute('data-scale-min');
            if (smin != null) this.options.scaleMin = getNum(smin, this.options.scaleMin, 0.01);
        }
        if (ds.scaleMax != null) {
            this.options.scaleMax = getNum(ds.scaleMax, this.options.scaleMax, 0.01);
        } else {
            const smax = this.canvas.getAttribute('data-scale-max');
            if (smax != null) this.options.scaleMax = getNum(smax, this.options.scaleMax, 0.01);
        }

        this.options.scaleMin = parseFloat(this.options.scaleMin);
        this.options.scaleMax = parseFloat(this.options.scaleMax);

        if (!Number.isFinite(this.options.scaleMin)) this.options.scaleMin = 0.5;
        if (!Number.isFinite(this.options.scaleMax)) this.options.scaleMax = 1.0;

        if (this.options.scaleMin > this.options.scaleMax) {
            const t = this.options.scaleMin;
            this.options.scaleMin = this.options.scaleMax; this.options.scaleMax = t;
        }

        const initialState = ds.initialState || this.canvas.getAttribute('data-initial-state');
        if (initialState && this.options.states.hasOwnProperty(initialState)) {
            this.currentState = initialState;
        }

        const petState = this.target.getAttribute('data-pet-state');
        if (petState && this.options.states.hasOwnProperty(petState)) {
            this.currentState = petState;
        }

        this.readPetLevel();
        this.loadSpriteSheet();
        this.startAnimation();
        this.listenForStateChanges();
        this.listenForLevelChanges();
    },

    /**
     * Read the pet level from the DOM
     */
    readPetLevel: function() {
        const petStatsContainer = this.canvas.closest('.block-body');
        if (petStatsContainer) {
            const levelText = petStatsContainer.querySelector('.petStats .u-alignCenter div:first-child');
            if (levelText) {
                const levelMatch = levelText.textContent.match(/\d+/);
                if (levelMatch) {
                    this.petLevel = parseInt(levelMatch[0]);
                    this.targetScale = this.calculateScale(this.petLevel);
                    this.currentScale = this.targetScale;
                }
            }
        }
    },

    /**
     * Calculates the scale value based on pet level.
     * Uses a non-linear (power 1.5) curve to slow scale growth at higher levels.
     *
     * @param {number} level - The pet's current level (1–100).
     * @returns {number} - The computed scale factor.
     */
    calculateScale: function(level) {
        level = Math.max(1, parseInt(level));

        const minScale = this.options.scaleMin;
        const maxScale = this.options.scaleMax;
        const maxLevel = 100;

        const progress = Math.pow(level / maxLevel, 1.5);
        let scale = minScale + progress * (maxScale - minScale);

        if (scale < minScale) scale = minScale;
        if (scale > maxScale) scale = maxScale;

        return scale;
    },

    /**
     * Load the sprite sheet image
     */
    loadSpriteSheet: function() {
        this.spriteSheetLoaded = false;

        this.spriteSheet = new Image();

        this.spriteSheet.onload = XF.proxy(function() {
            this.spriteSheetLoaded = true;
            console.log('PetRenderer: Sprite sheet loaded');
        }, this);

        this.spriteSheet.onerror = function() {
            console.error('PetRenderer: Failed to load sprite sheet');
        };

        this.spriteSheet.src = this.options.spriteSheetPath;
    },

    /**
     * Start the animation loop
     */
    startAnimation: function() {
        if (this.animationInterval) {
            clearInterval(this.animationInterval);
        }

        const interval = Math.floor(1000 / this.options.fps);

        this.animationInterval = setInterval(XF.proxy(this.update, this), interval);
    },

    /**
     * Update animation state and render the current frame
     */
    update: function() {
        this.currentFrame = (this.currentFrame + 1) % this.options.framesPerAnimation;

        if (this.scaleAnimationActive && this.currentScale !== this.targetScale) {
            const diff = this.targetScale - this.currentScale;
            this.currentScale += diff * 0.1;

            if (Math.abs(diff) < 0.01) {
                this.currentScale = this.targetScale;
                this.scaleAnimationActive = false;
            }
        }

        this.render();
    },

    /**
     * Render the current animation frame to the canvas
     */
    render: function() {
        if (!this.spriteSheetLoaded) {
            return;
        }

        const ctx = this.context;
        const canvas = this.canvas;
        const fw = Math.max(1, this.options.frameWidth);
        const fh = Math.max(1, this.options.frameHeight);

        ctx.clearRect(0, 0, canvas.width, canvas.height);

        const rowIndex = this.options.states[this.currentState] || 0;
        const sx = this.currentFrame * fw;
        const sy = rowIndex * fh;

        // Normalized visual sizing:
        // - Base box is 128×128 at scale 0.5
        // - Grows linearly to 192×192 at scale 1.0
        // - Clamp to [0.5, 1.0] for visual mapping regardless of configured bounds
        const s = Math.min(1.0, Math.max(0.5, this.currentScale));
        const t = (s - 0.5) / 0.5;
        const targetBox = 128 + t * (192 - 128);

        let drawW, drawH;
        if (fw >= fh) {
            drawW = targetBox;
            drawH = targetBox * (fh / fw);
        } else {
            drawH = targetBox;
            drawW = targetBox * (fw / fh);
        }

        const dx = (canvas.width - drawW) / 2;
        const dy = canvas.height - drawH;

        ctx.drawImage(
            this.spriteSheet,
            sx, sy,
            fw, fh,
            dx, dy,
            drawW, drawH
        );
    },

    /**
     * Change the animation state
     *
     * @param {string} state New animation state
     */
    changeState: function(state) {
        if (!this.options.states.hasOwnProperty(state)) {
            console.warn('PetRenderer: Unknown state: ' + state);
            return;
        }

        this.currentState = state;
        this.currentFrame = 0;
    },

    /**
     * Listen for pet state changes
     */
    listenForStateChanges: function() {
        document.addEventListener('petStateChanged', XF.proxy(function(e) {
            if (e.detail && e.detail.state) {
                this.changeState(e.detail.state);
            }
        }, this));

        const actionButtons = document.querySelectorAll('.pet-action-button');
        for (let i = 0; i < actionButtons.length; i++) {
            actionButtons[i].addEventListener('click', XF.proxy(function(e) {
                const action = e.currentTarget.getAttribute('data-action');
                if (action) {
                    this.changeState(action);

                    setTimeout(XF.proxy(function() {
                        this.changeState('idle');
                    }, this), 3000);
                }
            }, this));
        }
    },

    /**
     * Listen for pet level changes
     */
    listenForLevelChanges: function() {
        document.addEventListener('petLevelChanged', XF.proxy(function(e) {
            if (e.detail && e.detail.level) {
                this.petLevel = parseInt(e.detail.level);
                this.targetScale = this.calculateScale(this.petLevel);
                this.scaleAnimationActive = true;
            }
        }, this));

        const petStatsContainer = this.canvas.closest('.block-body');
        if (petStatsContainer) {
            const levelText = petStatsContainer.querySelector('.petStats .u-alignCenter div:first-child');
            if (levelText) {
                const observer = new MutationObserver(XF.proxy(function(mutations) {
                    for (let mutation of mutations) {
                        if (mutation.type === 'characterData' || mutation.type === 'childList') {
                            this.readPetLevel();
                            this.scaleAnimationActive = true;
                        }
                    }
                }, this));

                observer.observe(levelText, {
                    childList: true,
                    characterData: true,
                    subtree: true
                });
            }
        }
    }
});

XF.Element.register('userpets-renderer', 'XF.PetRenderer');