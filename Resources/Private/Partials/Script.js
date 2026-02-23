<f:variable name="introMessageLLL" value="{f:translate(key: 'easychat_introMessage')}"/>
<f:variable name="introMessage">{settings.introMessage ?: introMessageLLL}</f:variable>

const EASYCHAT_COOKIE_SESSION = 'easychat_session_id';
const EASYCHAT_CONSENT_SETTING = '{settings.enableDataProtectionConsent}';
const EASYCHAT_INTRO_MESSAGE  = `{introMessage}`;
const easychat = document.querySelector('.easychat');
const consent = easychat.querySelector('.easychat-consent');
const consentButton = easychat.querySelector('.consent-button');
const revokeButton = easychat.querySelector('.revoke-button');
const deepchat = document.getElementById('chat-assistant');
let easychatSession = getCookie(EASYCHAT_COOKIE_SESSION );

deepchat.setAttribute(
    "introMessage",
    JSON.stringify({
        text: EASYCHAT_INTRO_MESSAGE.replace(/\r?\n/g, "\n")
    })
);

if (EASYCHAT_CONSENT_SETTING === 'consent') {
    if (easychatSession === false) {
        consent.hidden = false;
    } else {
        deepchat.hidden = false;
        revokeButton.hidden = false;
    }

    consentButton.addEventListener('click', () => {
        setCookie(EASYCHAT_COOKIE_SESSION , self.crypto.randomUUID(), '{settings.cookieExpirationMinutes}');
        consent.hidden = true;
        deepchat.hidden = false;
        revokeButton.hidden = false;
    });

    revokeButton.addEventListener('click', () => {
        removeCookie('easychat_session_id');
        consent.hidden = false;
        deepchat.hidden = true;
        revokeButton.hidden = true;
    });
} else if (EASYCHAT_CONSENT_SETTING === 'without') {
    if (easychatSession === false) {
        setCookie(EASYCHAT_COOKIE_SESSION , self.crypto.randomUUID(), '{settings.cookieExpirationMinutes}');
    }
    deepchat.hidden = false;
} else {
    easychat.innerHTML = `<p style="background-color: yellow; padding: 0.5em 1em;">To run the chat assistant, your cookie management needs to set a cookie named <b>easychat_session_id</b>.</p>`;
}

function setCookie(cname, cvalue, exminutes) {
    const d = new Date();
    d.setTime(d.getTime() + (exminutes * 60 * 1000));
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
