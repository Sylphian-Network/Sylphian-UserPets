XF.PetActions = XF.Element.newHandler({
    options: {
        actionUrl: null,
        cooldown: 0
    },

    container: null,
    buttons: null,
    cooldownTimer: null,
    cooldownDisplay: null,
    cooldownInterval: null,

    init: function() {
        this.container = this.target;

        this.buttons = this.container.querySelectorAll('.pet-action-button');
        this.cooldownDisplay = this.container.querySelector('.cooldown-timer');
        this.cooldownTimer = this.container.querySelector('.js-pet-cooldown-timer');

        for (let i = 0; i < this.buttons.length; i++) {
            this.buttons[i].addEventListener('click', this.performAction.bind(this));
        }

        this.checkExistingCooldown();
    },

    performAction: function(e) {
        e.preventDefault();

        if (e.currentTarget.classList.contains('button--disabled')) {
            return;
        }

        const action = e.currentTarget.getAttribute('data-action');

        XF.ajax('post', this.options.actionUrl, {
            action: action
        }, XF.proxy(this, 'actionComplete'), {
            skipDefaultSuccess: true
        });

        this.disableButtons();
    },

    actionComplete: function(data) {
        if (data.success) {
            this.updatePetStats(data);

            const cooldownTime = data.cooldownTime || this.options.cooldown;
            this.startCooldownTimer(cooldownTime);

            XF.flashMessage(data.message || XF.phrase('sylphian_userpets_action_success'), 3000);
        } else {
            XF.alert(data.message || XF.phrase('sylphian_userpets_action_error'));

            if (data.cooldownRemaining) {
                this.startCooldownTimer(data.cooldownRemaining);
            } else {
                this.enableButtons();
            }
        }
    },

    updatePetStats: function(data) {
        const statBars = this.container.querySelectorAll('.petProgressBar');

        const hunger = statBars[0];
        hunger.setAttribute('title', data.hunger + '/100');
        hunger.querySelector('.petProgressBar-progress').style.width = data.hunger + '%';
        hunger.querySelector('.petProgressBar-label').textContent = data.hunger + '/100';

        const happiness = statBars[1];
        happiness.setAttribute('title', data.happiness + '/100');
        happiness.querySelector('.petProgressBar-progress').style.width = data.happiness + '%';
        happiness.querySelector('.petProgressBar-label').textContent = data.happiness + '/100';

        const sleepiness = statBars[2];
        sleepiness.setAttribute('title', data.sleepiness + '/100');
        sleepiness.querySelector('.petProgressBar-progress').style.width = data.sleepiness + '%';
        sleepiness.querySelector('.petProgressBar-label').textContent = data.sleepiness + '/100';
    },

    disableButtons: function() {
        for (let i = 0; i < this.buttons.length; i++) {
            this.buttons[i].classList.add('button--disabled');
            this.buttons[i].disabled = true;
        }
    },

    enableButtons: function() {
        for (let i = 0; i < this.buttons.length; i++) {
            this.buttons[i].classList.remove('button--disabled');
            this.buttons[i].disabled = false;
        }
    },

    checkExistingCooldown: function() {
        const lastActionTime = parseInt(this.container.getAttribute('data-last-action-time') || 0);
        const serverTime = parseInt(this.container.getAttribute('data-current-time') || 0);
        const cooldownTime = this.options.cooldown;

        if (lastActionTime && serverTime) {
            const elapsed = Math.floor((serverTime - lastActionTime));

            if (elapsed < cooldownTime) {
                const remaining = cooldownTime - elapsed;
                this.startCooldownTimer(remaining);
            }
        }
    },

    startCooldownTimer: function(seconds) {
        const self = this;
        let remaining = seconds || this.options.cooldown;

        if (this.cooldownInterval) {
            clearInterval(this.cooldownInterval);
        }

        this.disableButtons();
        this.cooldownDisplay.style.display = '';
        this.cooldownTimer.textContent = remaining;

        this.cooldownInterval = setInterval(function() {
            remaining--;
            self.cooldownTimer.textContent = remaining;

            if (remaining <= 0) {
                clearInterval(self.cooldownInterval);
                self.enableButtons();
                self.cooldownDisplay.style.display = 'none';
            }
        }, 1000);
    }
});

XF.Element.register('pet-actions', 'XF.PetActions');