/* app.js - small helpers used on every logged-in page.
   Load this after liquid-tabbar.js. */

// ---- bottom bar: tapping a tab goes to that page ----
// member pages by default. admin pages put their own page on each tab
// as data-href, and that wins if present.
var tabPages = ["home.php", "fitness.php", "progress.php", "profile.php"];
var bar = document.getElementById("bar");
if (bar) {
    var tabButtons = document.querySelectorAll(".tab");
    for (var t = 0; t < tabButtons.length; t++) {
        if (tabButtons[t].dataset.href) {
            tabPages[Number(tabButtons[t].dataset.i)] = tabButtons[t].dataset.href;
        }
    }

    var currentTab = document.querySelector(".tab.on");
    var currentIndex = currentTab ? Number(currentTab.dataset.i) : -1;

    bar.addEventListener("tabchange", function (e) {
        if (e.detail.index == currentIndex) {
            return;
        }
        // short pause so the lens finishes sliding before the page changes
        setTimeout(function () {
            window.location.href = tabPages[e.detail.index];
        }, 240);
    });
}

// ---- filter capsules: play the grow / shrink before the page changes ----
// The filters are ordinary links, so the page would normally jump straight
// to the new one. We swap the highlight first, let the animation run, then go.
var filterLinks = document.querySelectorAll(".filters a");
for (var f = 0; f < filterLinks.length; f++) {
    filterLinks[f].addEventListener("click", function (e) {
        if (this.classList.contains("on")) {
            e.preventDefault();      // already chosen, nothing to do
            return;
        }
        e.preventDefault();
        var target = this.href;
        for (var g = 0; g < filterLinks.length; g++) {
            filterLinks[g].classList.remove("on");
        }
        this.classList.add("on");
        setTimeout(function () {
            window.location.href = target;
        }, 340);
    });
}

// ---- "tap again to confirm" for anything that deletes ----
// The browser's own "Are you sure?" pop-up is blocked in some browsers, and
// then the button silently does nothing. So instead the first tap turns the
// button into "Sure? Tap again", and only a second tap within 4 seconds goes
// through. Put class="confirm-btn" on the button.
var confirmButtons = document.querySelectorAll(".confirm-btn");
for (var b = 0; b < confirmButtons.length; b++) {
    confirmButtons[b].addEventListener("click", function (e) {
        var btn = this;
        if (btn.dataset.armed == "yes") {
            return;                       // second tap, let the form submit
        }
        e.preventDefault();
        btn.dataset.armed = "yes";
        btn.dataset.label = btn.textContent;
        btn.textContent = "Sure? Tap again";
        btn.classList.add("armed");
        setTimeout(function () {
            btn.dataset.armed = "";
            btn.textContent = btn.dataset.label;
            btn.classList.remove("armed");
        }, 4000);
    });
}

// ---- keep the scroll position when a form reloads the page ----
// Ticking a meal or saving water submits a form, which reloads the page and
// would jump back to the top. We note where the user was just before the
// submit and jump back there once the new page has loaded.
var scrollKey = "scrollY:" + window.location.pathname;

var forms = document.querySelectorAll("form");
for (var i = 0; i < forms.length; i++) {
    forms[i].addEventListener("submit", function () {
        try {
            sessionStorage.setItem(scrollKey, String(window.scrollY));
        } catch (err) {
            // private mode or storage blocked, nothing to do
        }
    });
}

try {
    var savedY = sessionStorage.getItem(scrollKey);
    if (savedY !== null) {
        sessionStorage.removeItem(scrollKey);
        window.scrollTo(0, Number(savedY));
    }
} catch (err) {
    // ignore
}
