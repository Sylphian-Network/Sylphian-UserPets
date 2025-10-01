XF.PetRenderer = XF.Element.newHandler({
    options: {
        spriteSheetPath: null,
        states: {
            'idle': 0,
            'feed': 1,
            'play': 2,
            'sleep': 3,
        },
        frameWidth: 128,
        frameHeight: 128,
        fps: 4,
        framesPerAnimation: 4
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
     * Calculate scale based on level.
     *
     * Growth is smooth and continuous using a logarithmic progression:
     * - Level 1 = minScale (0.75x)
     * - Level 100 = maxScale (1.5x)
     *
     * Values in between are calculated using:
     *   scale = minScale + (log(level) / log(maxLevel)) * (maxScale - minScale)
     *
     * This gives faster growth at lower levels and slower growth at higher levels,
     * creating a more natural RPG-style curve instead of stepwise jumps.
     *
     * @param {number} level Pet's current level
     * @return {number} Scale factor to apply
     */
    calculateScale: function(level) {
        level = Math.max(1, parseInt(level));

        const minScale = 0.75;
        const maxScale = 1.5;
        const maxLevel = 100;

        const progress = Math.log(level) / Math.log(maxLevel);

        return minScale + progress * (maxScale - minScale);
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

        this.context.clearRect(0, 0, this.canvas.width, this.canvas.height);

        const rowIndex = this.options.states[this.currentState] || 0;

        const sx = this.currentFrame * this.options.frameWidth;
        const sy = rowIndex * this.options.frameHeight;

        const spriteWidth = this.options.frameWidth * this.currentScale;
        const spriteHeight = this.options.frameHeight * this.currentScale;

        const maxWidth = this.canvas.width * 0.95;
        const maxHeight = this.canvas.height * 0.95;

        let finalWidth = spriteWidth;
        let finalHeight = spriteHeight;

        if (spriteWidth > maxWidth) {
            const scale = maxWidth / spriteWidth;
            finalWidth *= scale;
            finalHeight *= scale;
        }

        if (finalHeight > maxHeight) {
            const scale = maxHeight / finalHeight;
            finalWidth *= scale;
            finalHeight *= scale;
        }

        const x = (this.canvas.width - finalWidth) / 2;
        const y = this.canvas.height - finalHeight;

        this.context.drawImage(
            this.spriteSheet,
            sx, sy,
            this.options.frameWidth, this.options.frameHeight,
            x, y,
            finalWidth, finalHeight
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