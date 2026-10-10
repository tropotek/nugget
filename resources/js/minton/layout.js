/*
Template Name: Minton - Admin & Dashboard Template
Author: CoderThemes
Version: 8.0.0
Website: https://coderthemes.com/
Contact: support@coderthemes.com
File: Layouts Js File
*/

// Nugget: lifted from Minton with three changes.
// - jQuery and Bootstrap are imported here rather than read from window, so neither is a global.
// - adjustLayout() used the `html` variable of the function below (out of scope there, so it threw on
//   any window 1140px wide or less). It now uses this.html.
// - The settings are kept in localStorage, not sessionStorage, so the light or dark choice outlasts the tab.
//   The <head> script in components/layouts/head.blade.php reads the same key.
import $ from 'jquery';
import * as bootstrap from 'bootstrap';

(function () {
    var savedConfig = localStorage.getItem("__MINTON_CONFIG__");

    var html = document.getElementsByTagName("html")[0];

    //  Default Config Value
    var defaultConfig = {
        theme: "light",

        layout: {
            position: "fixed",
            width: "fluid",
        },

        topbar: {
            color: "brand",
        },

        menu: {
            color: "light",
        },

        // This option for only vertical (left Sidebar) layout
        sidebar: {
            size: "default",
            user: false,
        },
    };

    var htmlElement = document.documentElement;
    var config = Object.assign(JSON.parse(JSON.stringify(defaultConfig)), {});

    var layoutColor = htmlElement.getAttribute('data-bs-theme');
    config['theme'] = layoutColor !== null ? layoutColor : defaultConfig.theme;

    var layoutPosition = htmlElement.getAttribute('data-layout-position');
    config['layout']['position'] = layoutPosition != null ? layoutPosition : defaultConfig.layout.position;

    var layoutWidth = htmlElement.getAttribute('data-layout-width');
    config['layout']['width'] = layoutWidth != null ? layoutWidth : defaultConfig.layout.width;

    var topbarColor = htmlElement.getAttribute('data-topbar-color');
    config['topbar']['color'] = topbarColor != null ? topbarColor : defaultConfig.topbar.color;

    var leftbarSize = htmlElement.getAttribute('data-sidebar-size');
    config['sidebar']['size'] = leftbarSize !== null ? leftbarSize : defaultConfig.sidebar.size;

    var sidebarUser = htmlElement.getAttribute('data-sidebar-user')
    config['sidebar']['user'] = sidebarUser !== null ? true : defaultConfig.sidebar.user;

    var menuColor = htmlElement.getAttribute('data-menu-color');
    config['menu']['color'] = menuColor !== null ? menuColor : defaultConfig.menu.color;

    window.defaultConfig = JSON.parse(JSON.stringify(config));

    if (savedConfig !== null) {
        config = JSON.parse(savedConfig);
    }

    window.config = config;

    if (config) {
        html.setAttribute("data-bs-theme", config.theme);
        html.setAttribute("data-topbar-color", config.topbar.color);
        html.setAttribute("data-menu-color", config.menu.color);
        html.setAttribute("data-sidebar-size", config.sidebar.size);
        html.setAttribute("data-layout-width", config.layout.width);
        html.setAttribute("data-layout-position", config.layout.position);

        if (config.sidebar.user && config.sidebar.user.toString() === "true") {
            html.setAttribute("data-sidebar-user", true);
        } else {
            html.removeAttribute("data-sidebar-user");
        }
    }

    if (window.innerWidth <= 991.98) {
        html.setAttribute("data-sidebar-size", "full");
    } else if (window.innerWidth >= 991.98 && window.innerWidth <= 1140) {
        if (self.config.sidebar.size !== 'full') {
            html.setAttribute("data-sidebar-size", "condensed");
        }
    }

    // if (document.getElementById('app-stylesheet').href.includes('rtl.min.css')) {
    //     document.getElementsByTagName('html')[0].dir = "rtl";
    // }
    
})();


class ThemeCustomizer {

    constructor() {
        this.html = document.getElementsByTagName('html')[0]
        this.config = {};
        this.defaultConfig = window.config;
        this.mainNavScrollbar = document.getElementsByClassName('scrollbar')[0];
    }

    initConfig() {
        this.defaultConfig = JSON.parse(JSON.stringify(window.defaultConfig));
        this.config = JSON.parse(JSON.stringify(window.config));
        this.setSwitchFromConfig();
    }

    // Add Scrollbar
    initScrollBars() {
        this.initLeftSidebar();
        document.querySelector('.left-side-menu .scrollbar')?.setAttribute("data-simplebar", "");
        document.querySelector('.left-side-menu .scrollbar')?.classList.add('h-100');
    }

    // Left Sidebar Menu (Vertical Menu)
    initLeftSidebar() {
        var self = this;

        if ($("#side-menu").length) {
            var navCollapse = $('#sidebar-menu li .collapse');
            var navToggle = $("#sidebar-menu li [data-bs-toggle='collapse']");
            navToggle.on('click', function (e) {
                return false;
            });

            // open one menu at a time only
            document.querySelectorAll('.side-nav .collapse').forEach(function (collapseEl) {
                collapseEl.addEventListener('show.bs.collapse', function (event) {
                    document.querySelectorAll('.side-nav .collapse.show').forEach(function (openEl) {
                        if (openEl !== event.target) {
                            new bootstrap.Collapse(openEl, {
                                toggle: false
                            }).hide();
                        }
                    });
                });
            });        
            

            // activate the menu in left side bar (Vertical Menu) based on url
            $("#side-menu a").each(function () {
                var pageUrl = window.location.href.split(/[?#]/)[0];
                if (this.href == pageUrl) {
                    $(this).addClass("active");
                    $(this).parent().addClass("menuitem-active");
                    $(this).parent().parent().parent().addClass("show");
                    $(this).parent().parent().parent().parent().addClass("menuitem-active");
                    $(this).parent().parent().parent().parent().parent().parent().parent().addClass("menuitem-active");

                    var firstLevelParent = $(this).parent().parent().parent().parent().parent().parent();
                    if (firstLevelParent.attr('id') !== 'sidebar-menu') firstLevelParent.addClass("show");

                    var secondLevelParent = $(this).parent().parent().parent().parent().parent().parent().parent().parent().parent();
                    if (secondLevelParent.attr('id') !== 'wrapper') secondLevelParent.addClass("show");

                    var upperLevelParent = $(this).parent().parent().parent().parent().parent().parent().parent().parent().parent().parent();
                    if (!upperLevelParent.is('body')) upperLevelParent.addClass("menuitem-active");
                }
            });
        }

        // handling two columns menu if present
        var twoColsidebar = $("#two-col-sidenav-main");
        if (twoColsidebar.length) {

            var twoColsidebarItems = $("#two-col-sidenav-main .nav-link");
            var sideSubMenus = $(".twocolumn-menu-item");

            var nav = $('.twocolumn-menu-item .nav-second-level');
            var navCollapse = $('#two-col-menu li .collapse');

            // open one menu at a time only
            navCollapse.on({
                'show.bs.collapse': function () {
                    var nearestNav = $(this).closest(nav).closest(nav).find(navCollapse);
                    if (nearestNav.length) nearestNav.not($(this)).collapse('hide'); else navCollapse.not($(this)).collapse('hide');
                }
            });

            twoColsidebarItems.on('click', function (e) {
                var target = $($(this).attr('href'));

                if (target.length) {
                    e.preventDefault();
                    twoColsidebarItems.removeClass('active');
                    $(this).addClass('active');
                    sideSubMenus.removeClass("d-block");
                    target.addClass("d-block");
                    if (window.innerWidth >= 1040) {
                        self.changeLeftbarSize("default");
                    }
                }
                return true;
            });

            // activate menu with no child
            var pageUrl = window.location.href; //.split(/[?#]/)[0];
            twoColsidebarItems.each(function () {
                if (this.href === pageUrl) {
                    $(this).addClass('active');
                }
            });


            // activate the menu in left side bar (Two column) based on url
            $("#two-col-menu a").each(function () {
                if (this.href == pageUrl) {
                    $(this).addClass("active");
                    $(this).parent().addClass("menuitem-active");
                    $(this).parent().parent().parent().addClass("show");
                    $(this).parent().parent().parent().parent().addClass("menuitem-active"); // add active to li of the current link

                    var firstLevelParent = $(this).parent().parent().parent().parent().parent().parent();
                    if (firstLevelParent.attr('id') !== 'sidebar-menu') firstLevelParent.addClass("show");

                    $(this).parent().parent().parent().parent().parent().parent().parent().addClass("menuitem-active");

                    var secondLevelParent = $(this).parent().parent().parent().parent().parent().parent().parent().parent().parent();
                    if (secondLevelParent.attr('id') !== 'wrapper') secondLevelParent.addClass("show");

                    var upperLevelParent = $(this).parent().parent().parent().parent().parent().parent().parent().parent().parent().parent();
                    if (!upperLevelParent.is('body')) upperLevelParent.addClass("menuitem-active");

                    // opening menu
                    var matchingItem = null;
                    var targetEl = '#' + $(this).parents('.twocolumn-menu-item').attr("id");
                    $("#two-col-sidenav-main .nav-link").each(function () {
                        if ($(this).attr('href') === targetEl) {
                            matchingItem = $(this);
                        }
                    });
                    if (matchingItem) matchingItem.trigger('click');
                }
            });
        }

        setTimeout(function () {
            var activatedItem = document.querySelector('li.menuitem-active .active');
            if (activatedItem != null) {
                var simplebarContent = document.querySelector('.left-side-menu .simplebar-content-wrapper');
                var offset = activatedItem.offsetTop - 300;
                if (simplebarContent && offset > 100) {
                    scrollTo(simplebarContent, offset, 600);
                }
            }
        }, 200);

        // scrollTo (Left Side Bar Active Menu)
        function easeInOutQuad(t, b, c, d) {
            t /= d / 2;
            if (t < 1) return c / 2 * t * t + b;
            t--;
            return -c / 2 * (t * (t - 2) - 1) + b;
        }
        function scrollTo(element, to, duration) {
            var start = element.scrollTop, change = to - start, currentTime = 0, increment = 20;
            var animateScroll = function () {
                currentTime += increment;
                var val = easeInOutQuad(currentTime, start, change, duration);
                element.scrollTop = val;
                if (currentTime < duration) {
                    setTimeout(animateScroll, increment);
                }
            };
            animateScroll();
        }
    }

    // Horizontal Layout Menu Setup
    initHorizontalLayout() {
        //activate the menu in topbar(horizontal menu) based on url
        $(".navbar-nav a").each(function () {
            var pageUrl = window.location.href.split(/[?#]/)[0];
            if (this.href == pageUrl) {
                $(this).addClass("active");
                $(this).parent().addClass("active");
                $(this).parent().parent().addClass("active");
                $(this).parent().parent().parent().addClass("active");
                $(this).parent().parent().parent().parent().addClass("active");
                var el = $(this).parent().parent().parent().parent().addClass("active").prev();
                if (el.hasClass("nav-link"))
                    el.addClass('active');
            }
        });
    }

    reverseQuery(element, query) {
        while (element) {
            if (element.parentElement) {
                if (element.parentElement.querySelector(query) === element) return element
            }
            element = element.parentElement;
        }
        return null;
    }

    changeThemeMode(color) {
        this.config.theme = color;
        this.html.setAttribute('data-bs-theme', color);
        this.setSwitchFromConfig();
    }

    changeLayoutPosition(position) {
        this.html.setAttribute('data-layout-position', position);
        this.config.layout.position = position;
        this.setSwitchFromConfig();
    }

    changeLayoutWidth(width, save = true) {
        this.html.setAttribute('data-layout-width', width);
        if (save) {
            this.config.layout.width = width;
            this.setSwitchFromConfig();
        }
    }

    changeMenuColor(color) {
        this.config.menu.color = color;
        this.html.setAttribute('data-menu-color', color);
        this.setSwitchFromConfig();
    }

    changeLeftbarSize(size, save = true) {
        this.html.setAttribute('data-sidebar-size', size);
        if (save) {
            this.config.sidebar.size = size;
            this.setSwitchFromConfig();
        }
    }

    changeTopbarColor(color) {
        this.config.topbar.color = color;
        this.html.setAttribute('data-topbar-color', color);
        this.setSwitchFromConfig();
    }

    changeSidebarUser(showUser) {

        this.config.sidebar.user = showUser;
        if (showUser) {
            this.html.setAttribute('data-sidebar-user', showUser);
        } else {
            this.html.removeAttribute('data-sidebar-user');
        }
        this.setSwitchFromConfig();
    }

    resetTheme() {
        this.config = JSON.parse(JSON.stringify(window.defaultConfig));
        this.changeThemeMode(this.config.theme);
        this.changeLayoutPosition(this.config.layout.position);
        this.changeLayoutWidth(this.config.layout.width);
        this.changeMenuColor(this.config.menu.color);
        this.changeTopbarColor(this.config.topbar.color);
        this.changeLeftbarSize(this.config.sidebar.size);
        this.adjustLayout();
    }

    initSwitchListener() {
        var self = this;

        document.querySelectorAll('input[name=data-layout]').forEach(function (element) {
            element.addEventListener('change', function (e) {
                self.changeLayout(element.value);
            })
        });


        document.querySelectorAll('input[name=data-menu-color]').forEach(function (element) {
            element.addEventListener('change', function (e) {
                self.changeMenuColor(element.value);
            })
        });

        document.querySelectorAll('input[name=data-sidebar-size]').forEach(function (element) {
            element.addEventListener('change', function (e) {
                self.changeLeftbarSize(element.value);
            })
        });


        document.querySelectorAll('input[name=data-bs-theme]').forEach(function (element) {
            element.addEventListener('change', function (e) {
                self.changeThemeMode(element.value);
            })
        });

        document.querySelectorAll('input[name=data-layout-position]').forEach(function (element) {
            element.addEventListener('change', function (e) {
                self.changeLayoutPosition(element.value);
            })
        });

        document.querySelectorAll('input[name=data-layout-width]').forEach(function (element) {
            element.addEventListener('change', function (e) {
                self.changeLayoutWidth(element.value);
            })
        });

        document.querySelectorAll('input[name=data-topbar-color]').forEach(function (element) {
            element.addEventListener('change', function (e) {
                self.changeTopbarColor(element.value);
            })
        });

        document.querySelectorAll('input[name=data-sidebar-user]').forEach(function (element) {
            element.addEventListener('change', function (e) {
                self.changeSidebarUser(element.checked);
            })
        });


        //TopBar Light Dark
        var themeColorToggle = document.getElementById('light-dark-mode');
        if (themeColorToggle) {
            themeColorToggle.addEventListener('click', function (e) {

                if (self.config.theme === 'light') {
                    self.changeThemeMode('dark');
                } else {
                    self.changeThemeMode('light');
                }
            });
        }

        var resetBtn = document.querySelector('#resetBtn')
        if (resetBtn) {
            resetBtn.addEventListener('click', function (e) {
                self.resetTheme();
            });
        }

        var menuToggleBtn = document.querySelector('.button-menu-mobile');
        if (menuToggleBtn) {
            menuToggleBtn.addEventListener('click', function () {
                var configSize = self.config.sidebar.size;
                var size = self.html.getAttribute('data-sidebar-size', configSize);

                if (size === 'full') {
                    self.showBackdrop();
                } else {
                    if (size === 'condensed') {
                        self.changeLeftbarSize(configSize == 'condensed' ? 'default' : configSize, false);
                    } else {
                        self.changeLeftbarSize('condensed', false);
                    }
                }

                // Todo: old implementation
                self.html.classList.toggle('sidebar-enable');

            });
        }
    }

    showBackdrop() {
        const backdrop = document.createElement('div');
        backdrop.id = 'custom-backdrop';
        backdrop.classList = 'offcanvas-backdrop fade show';
        document.body.appendChild(backdrop);

        if (document.getElementsByTagName('html')[0]?.getAttribute('dir') != 'rtl') {
            document.body.style.overflow = "hidden";
            if (window.innerWidth > 1140) {
                document.body.style.paddingRight = "15px";
            }
        }
        const self = this
        backdrop.addEventListener('click', function (e) {
            self.html.classList.remove('sidebar-enable');
            self.hideBackdrop();
        })
    }

    hideBackdrop() {
        var backdrop = document.getElementById('custom-backdrop');
        if (backdrop) {
            document.body.removeChild(backdrop);
            document.body.style.overflow = null;
            document.body.style.paddingRight = null;
        }
    }

    initWindowSize() {
        var self = this;
        window.addEventListener('resize', function (e) {
            self.adjustLayout();
        })
    }

    adjustLayout() {
        var self = this;

        if (window.innerWidth <= 991.98) {
            self.html.setAttribute("data-sidebar-size", "full");
        } else if (window.innerWidth >= 991.98 && window.innerWidth <= 1140) {
            if (self.config.sidebar.size !== 'full') {
                self.html.setAttribute("data-sidebar-size", "condensed");
            }
        } else {
            self.changeLeftbarSize(self.config.sidebar.size);
        }
    }

    setSwitchFromConfig() {

        localStorage.setItem('__MINTON_CONFIG__', JSON.stringify(this.config));

        document.querySelectorAll('.right-bar input[type=checkbox]').forEach(function (checkbox) {
            checkbox.checked = false;
        })

        var config = this.config;
        if (config) {
            var layoutColorSwitch = document.querySelector('input[type=checkbox][name=data-bs-theme][value=' + config.theme + ']');
            var layoutWidthSwitch = document.querySelector('input[type=checkbox][name=data-layout-width][value=' + config.layout.width + ']');
            var layoutPositionSwitch = document.querySelector('input[type=checkbox][name=data-layout-position][value=' + config.layout.position + ']');
            var topbarColorSwitch = document.querySelector('input[type=checkbox][name=data-topbar-color][value=' + config.topbar.color + ']');
            var menuColorSwitch = document.querySelector('input[type=checkbox][name=data-menu-color][value=' + config.menu.color + ']');
            var leftbarSizeSwitch = document.querySelector('input[type=checkbox][name=data-sidebar-size][value=' + config.sidebar.size + ']');
            var leftbarUserSwitch = document.querySelector('input[type=checkbox][name=data-sidebar-user]');

            if (layoutColorSwitch) layoutColorSwitch.checked = true;
            if (layoutPositionSwitch) layoutPositionSwitch.checked = true;
            if (layoutWidthSwitch) layoutWidthSwitch.checked = true;
            if (topbarColorSwitch) topbarColorSwitch.checked = true;
            if (menuColorSwitch) menuColorSwitch.checked = true;
            if (leftbarSizeSwitch) leftbarSizeSwitch.checked = true;
            if (leftbarUserSwitch && config.sidebar.user.toString() === "true") leftbarUserSwitch.checked = true;
        }
    }


    init() {
        this.initConfig();
        this.initScrollBars();
        this.initLeftSidebar();
        this.initHorizontalLayout();
        this.initSwitchListener();
        this.initWindowSize();
        this.adjustLayout();
        this.setSwitchFromConfig();
    }
}

new ThemeCustomizer().init();