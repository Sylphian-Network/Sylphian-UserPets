XF.PetRenderer = XF.Element.newHandler({
    options: {
        spriteSheetPath: null,
        states: {
            'idle': 0,
            'feed': 1,
            'play': 2,
            'sleep': 3
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
    lastFrameTime: 0,

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

        this.loadSpriteSheet();

        this.startAnimation();

        this.listenForStateChanges();
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

        this.context.drawImage(
            this.spriteSheet,
            sx, sy,
            this.options.frameWidth, this.options.frameHeight,
            0, 0,
            this.canvas.width, this.canvas.height
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
    }
});

XF.Element.register('userpets-renderer', 'XF.PetRenderer');