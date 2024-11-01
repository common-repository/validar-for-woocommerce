window.ValidarAdmin = window.ValidarAdmin || {};

window.ValidarAdmin.monitorAccountConnection = function () {
  /*
  Every 2.5 seconds check if we are now connected to Validar, if so
  refresh the page to show the updated contents
  */
  function checkStatus() {
    jQuery.ajax({
      type: 'POST',
      url: ___validar.ajax,
      data: {
        action: 'validar_connection_status',
      },
      success: function (res) {
        if (res.data) {
          location.reload();
        }
      }
    });
  }

  setInterval(checkStatus, 2500);
};
