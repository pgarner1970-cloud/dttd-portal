(function(){
  function ready(fn){
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  ready(function(){
    const select = document.getElementById('venue_id_select');
    if (!select) return;

    const map = {
      venueName: document.getElementById('venue_name_input'),
      venueAddress: document.getElementById('venue_address_input'),
      venuePostcode: document.getElementById('venue_postcode_input'),
      venueFacebook: document.getElementById('venue_facebook_url_input'),
      venueWebsite: document.getElementById('venue_website_url_input'),
      venueInstagram: document.getElementById('venue_instagram_url_input'),
      venueSocialLabel: document.getElementById('venue_social_label_input')
    };

    select.addEventListener('change', function(){
      const option = select.options[select.selectedIndex];
      if (!option || select.value === '0') return;

      if (map.venueName) map.venueName.value = option.dataset.venueName || '';
      if (map.venueAddress) map.venueAddress.value = option.dataset.venueAddress || '';
      if (map.venuePostcode) map.venuePostcode.value = option.dataset.venuePostcode || '';
      if (map.venueFacebook) map.venueFacebook.value = option.dataset.venueFacebook || '';
      if (map.venueWebsite) map.venueWebsite.value = option.dataset.venueWebsite || '';
      if (map.venueInstagram) map.venueInstagram.value = option.dataset.venueInstagram || '';
      if (map.venueSocialLabel) map.venueSocialLabel.value = option.dataset.venueSocialLabel || '';
    });
  });
})();
