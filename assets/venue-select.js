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

    const fields = {
      venueName: document.getElementById('venue_name_input'),
      venueAddress: document.getElementById('venue_address_input'),
      venuePostcode: document.getElementById('venue_postcode_input'),
      venueFacebook: document.getElementById('venue_facebook_url_input'),
      venueWebsite: document.getElementById('venue_website_url_input'),
      venueInstagram: document.getElementById('venue_instagram_url_input'),
      venueTicket: document.getElementById('venue_ticket_url_input'),
      eventTicket: document.getElementById('ticketing_url_input'),
      venueSocialLabel: document.getElementById('venue_social_label_input')
    };

    function setField(key, value){
      if (fields[key]) fields[key].value = value || '';
    }

    select.addEventListener('change', function(){
      const option = select.options[select.selectedIndex];

      if (!option || select.value === '0') {
        Object.keys(fields).forEach(function(key){
          setField(key, '');
        });
        return;
      }

      setField('venueName', option.dataset.venueName);
      setField('venueAddress', option.dataset.venueAddress);
      setField('venuePostcode', option.dataset.venuePostcode);
      setField('venueFacebook', option.dataset.venueFacebook);
      setField('venueWebsite', option.dataset.venueWebsite);
      setField('venueInstagram', option.dataset.venueInstagram);
      setField('venueTicket', option.dataset.venueTicket);
      if (fields.eventTicket && !fields.eventTicket.value) {
        setField('eventTicket', option.dataset.venueTicket);
      }
      setField('venueSocialLabel', option.dataset.venueSocialLabel);
    });
  });
})();
