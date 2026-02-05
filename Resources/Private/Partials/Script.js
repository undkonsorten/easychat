const EASYCHAT_COOKIE_SESSION = 'easychat_session_id';
const EASYCHAT_COOKIE_NAGSCREEN = 'easychat_nagscreen';
const EASYCHAT_CONSENT_SETTING = '{settings.enableDataProtectionConsent}';
const easychat = document.querySelector('.easychat');
const easychatNagscreen = easychat.querySelector('.easychat__nagscreen');
const easychatToggles = easychat.querySelectorAll('.easychat__toggle');
const chatbot = document.querySelector('.chatbot');
const header = chatbot.querySelector('.chatbot__header');
const consent = chatbot.querySelector('.chatbot__consent');
const consentButton = consent.querySelector('.consent-button');
const revoke = easychat.querySelector('.revoke');
const revokeButton = revoke.querySelector('.revoke-button');
const deepchat = document.getElementById('chat-assistant');
let easychatSession = getCookie(EASYCHAT_COOKIE_SESSION );

if (getCookie(EASYCHAT_COOKIE_NAGSCREEN) === false) {
    easychatNagscreen.hidden = false;
}

easychatNagscreen.querySelector('button').addEventListener('click', () => {
    easychatNagscreen.hidden = true;
    setCookie(EASYCHAT_COOKIE_NAGSCREEN , 'hidden', 30);
})

for (const easychatToggle of easychatToggles) {
    easychatToggle.addEventListener('click', function() {
        chatbot.hidden = !chatbot.hidden;
        easychatNagscreen.hidden = true;

        for (const easychatToggle of easychatToggles) {
            easychatToggle.ariaExpanded = !chatbot.hidden;
        }
    });
}

if (EASYCHAT_CONSENT_SETTING === 'consent') {
    if (easychatSession === false) {
        consent.hidden = false;
    } else {
        deepchat.hidden = false;
        header.hidden = false;
        // revoke.hidden = false;
    }

    consentButton.addEventListener('click', () => {
        setCookie(EASYCHAT_COOKIE_SESSION , self.crypto.randomUUID(), 30);
        consent.hidden = true;
        deepchat.hidden = false;
        header.hidden = false;
        // revoke.hidden = false;
    });

    revokeButton.addEventListener('click', () => {
        removeCookie('easychat_session_id');
        consent.hidden = false;
        deepchat.hidden = true;
        header.hidden = true;
        revoke.hidden = true;
    });
} else if (EASYCHAT_CONSENT_SETTING === 'without') {
    if (easychatSession === false) {
        setCookie(EASYCHAT_COOKIE_SESSION , self.crypto.randomUUID(), 30);
    }
    deepchat.hidden = false;
    header.hidden = false;
} else {
    chatbot.querySelector('.chatbot__stage').innerHTML = `<div style="padding: 1rem;">Your cookie management needs to set a cookie named <b>easychat_session_id</b> to run the chatbot.</div>`;
}

deepchat.addEventListener('render', (e) => {
    addIntroMessage(deepchat);

    consentButton.addEventListener('click', () => {
        deepchat.focusInput();
        deepchat.clearMessages();
        deepchat.addMessage({text: "Datenschutzerklärung zugestimmt", role: "moderator"});
        addIntroMessage(deepchat);
    });
});

function addIntroMessage(chatbotReference) {
    const introMessage  = `{settings.introMessage}`;
    chatbotReference.addMessage({text: introMessage.replace(/\r?\n/g, "\n")});
}

function setCookie(cname, cvalue, exdays) {
    const d = new Date();
    d.setTime(d.getTime() + (exdays * 24 * 60 * 60 * 1000));
    let expires = "expires=" + d.toUTCString();
    document.cookie = cname + "=" + cvalue + ";" + expires + ";path=/";
}

function removeCookie(cname) {
    document.cookie = cname + "=; expires=Thu, 01 Jan 1970 00:00:00 UTC;path=/";
}

function getCookie(cname) {
    let name = cname + "=";
    let ca = document.cookie.split(';');
    for (let i = 0; i < ca.length; i++) {
        let c = ca[i];
        while (c.charAt(0) === ' ') {
            c = c.substring(1);
        }
        if (c.indexOf(name) === 0) {
            return c.substring(name.length, c.length);
        }
    }
    return false;
}
