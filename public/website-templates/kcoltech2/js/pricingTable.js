/* ------------------------------------------------------------------
 * Name         : RigelHost - HTML Template
 * File         : pricingTable.js
 * Version      : 1.0
 * Author       : ScanThemes
 * Author URI   : scanthemes@gmail.com
 *
 * Copyright 2018 All Rights Reserved.
/* ------------------------------------------------------------------*/
/* ------------------------------------------------------------------*/

  "use strict";

  var headertext = [],
      headers = document.querySelectorAll("#pricing-table th"),
      tablerows = document.querySelectorAll("#pricing-table th"),
      tablebody = document.querySelector("#pricing-table tbody");

      for(var i = 0; i < headers.length; i++) {
        var current = headers[i];
        headertext.push(current.textContent.replace(/\r?\n|\r/,""));
      }
      for (var i = 0, row; row = tablebody.rows[i]; i++) {
        for (var j = 0, col; col = row.cells[j]; j++) {
          col.setAttribute("data-th", headertext[j]);
        }
      }
