/*
Template Name: Minton - Admin & Dashboard Template
Author: CoderThemes
Version: 8.0.0
Website: https://coderthemes.com/
Contact: support@coderthemes.com
File: Main Js File
*/

// Nugget: lifted from Minton and cut down to the parts that need no plugin package.
// - jQuery and Bootstrap are imported here rather than read from window, so neither is a global.
// - Removed: the theme settings panel, the toast and alert demos, and the setup for select2, input masks,
//   tippy, Waves, counterup, peity, knob, the date and time pickers, touchspin and maxlength.
// - The password show/hide handler was bound twice (so a click toggled it and toggled it back); it is
//   bound once, in initShowHidePassword().
import $ from 'jquery';
import * as bootstrap from 'bootstrap';
import 'simplebar';

(function ($) {

  'use strict';

  // Bootstrap Components
  function initComponents() {

      // loader - Preloader
      $(window).on('load', function () {
          $('#status').fadeOut();
          $('#preloader').delay(350).fadeOut('slow');
      });

      // Popovers
      const popoverTriggerList = document.querySelectorAll('[data-bs-toggle="popover"]')
      const popoverList = [...popoverTriggerList].map(popoverTriggerEl => new bootstrap.Popover(popoverTriggerEl))

      // Tooltips
      const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]')
      const tooltipList = [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl))

      // offcanvas
      const offcanvasElementList = document.querySelectorAll('.offcanvas')
      const offcanvasList = [...offcanvasElementList].map(offcanvasEl => new bootstrap.Offcanvas(offcanvasEl))

      //Toasts
      var toastElList = [].slice.call(document.querySelectorAll('.toast'))
      var toastList = toastElList.map(function (toastEl) {
          return new bootstrap.Toast(toastEl)
      })

      $('.dropdown-menu a.dropdown-toggle').on('click', function (e) {
          if (!$(this).next().hasClass('show')) {
              $(this).parents('.dropdown-menu').first().find('.show').removeClass("show");
          }
          var $subMenu = $(this).next(".dropdown-menu");
          $subMenu.toggleClass('show');

          return false;
      });
  }

  // Portlet Widget (Card Reload, Collapse, and Delete)
  function initPortletCard() {

      var portletIdentifier = ".card"
      var portletCloser = '.card a[data-toggle="remove"]'
      var portletRefresher = '.card a[data-toggle="reload"]'
      let self = this

      // Panel closest
      $(document).on("click", portletCloser, function (ev) {
          ev.preventDefault();
          var $portlet = $(this).closest(portletIdentifier);
          var $portlet_parent = $portlet.parent();
          $portlet.remove();
          if ($portlet_parent.children().length == 0) {
              $portlet_parent.remove();
          }
      });

      // Panel Reload
      $(document).on("click", portletRefresher, function (ev) {
          ev.preventDefault();
          var $portlet = $(this).closest(portletIdentifier);
          // This is just a simulation, nothing is going to be reloaded
          $portlet.append('<div class="card-disabled"><div class="card-portlets-loader"><div class="spinner-border text-primary m-2" role="status"></div></div></div>');
          var $pd = $portlet.find('.card-disabled');
          setTimeout(function () {
              $pd.fadeOut('fast', function () {
                  $pd.remove();
              });
          }, 500 + 300 * (Math.random() * 5));
      });
  }

  //  Multi Dropdown
  function initMultiDropdown() {
      $('.dropdown-menu a.dropdown-toggle').on('click', function () {
          var dropdown = $(this).next('.dropdown-menu');
          var otherDropdown = $(this).parent().parent().find('.dropdown-menu').not(dropdown);
          otherDropdown.removeClass('show')
          otherDropdown.parent().find('.dropdown-toggle').removeClass('show')
          return false;
      });
  }

  // Topbar Search Form
  function initSearch() {
      // Serach Toggle
      var navDropdowns = $('.navbar-custom .dropdown:not(.app-search)');

      // hide on other click
      $(document).on('click', function (e) {
          if (e.target.id == "top-search" || e.target.closest('#search-dropdown')) {
              $('#search-dropdown').addClass('d-block');
          } else {
              $('#search-dropdown').removeClass('d-block');
          }
          return true;
      });

      // Serach Toggle
      $('#top-search').on('focus', function (e) {
          e.preventDefault();
          navDropdowns.children('.dropdown-menu.show').removeClass('show');
          $('#search-dropdown').addClass('d-block');
          return false;
      });

      // hide search on opening other dropdown
      navDropdowns.on('show.bs.dropdown', function () {
          $('#search-dropdown').removeClass('d-block');
      });
  }

  // Topbar Fullscreen Button
  function initfullScreenListener() {
      var self = this;
      var fullScreenBtn = document.querySelector('[data-toggle="fullscreen"]');

      if (fullScreenBtn) {
          fullScreenBtn.addEventListener('click', function (e) {
              e.preventDefault();
              document.body.classList.toggle('fullscreen-enable')
              if (!document.fullscreenElement && /* alternative standard method */ !document.mozFullScreenElement && !document.webkitFullscreenElement) {  // current working methods
                  if (document.documentElement.requestFullscreen) {
                      document.documentElement.requestFullscreen();
                  } else if (document.documentElement.mozRequestFullScreen) {
                      document.documentElement.mozRequestFullScreen();
                  } else if (document.documentElement.webkitRequestFullscreen) {
                      document.documentElement.webkitRequestFullscreen(Element.ALLOW_KEYBOARD_INPUT);
                  }
              } else {
                  if (document.cancelFullScreen) {
                      document.cancelFullScreen();
                  } else if (document.mozCancelFullScreen) {
                      document.mozCancelFullScreen();
                  } else if (document.webkitCancelFullScreen) {
                      document.webkitCancelFullScreen();
                  }
              }
          });
      }
  }

  // Show/Hide Password
  function initShowHidePassword() {
      $("[data-password]").on('click', function () {
          if ($(this).attr('data-password') == "false") {
              $(this).siblings("input").attr("type", "text");
              $(this).attr('data-password', 'true');
              $(this).addClass("show-password");
          } else {
              $(this).siblings("input").attr("type", "password");
              $(this).attr('data-password', 'false');
              $(this).removeClass("show-password");
          }
      });
  }

  // Form Validation
  function initFormValidation() {
      // Example starter JavaScript for disabling form submissions if there are invalid fields
      // Fetch all the forms we want to apply custom Bootstrap validation styles to
      // Loop over them and prevent submission
      document.querySelectorAll('.needs-validation').forEach(form => {
          form.addEventListener('submit', event => {
              if (!form.checkValidity()) {
                  event.preventDefault()
                  event.stopPropagation()
              }

              form.classList.add('was-validated')
          }, false)
      })
  }

  function init() {
      initComponents();
      initPortletCard();
      initMultiDropdown();
      initSearch();
      initfullScreenListener();
      initShowHidePassword();
      initFormValidation();
  }

  init();

})($)
