function dynamicAjaxReloader(scroll = false, progress = false) {
    var $reloadBlocks = $('.jsAjaxReload');

    if ($reloadBlocks.length > 0) {
        $.ajax({
            type: 'POST',
            url: window.location.href,
            dataType: 'html',
            success: function (response) {
                if ($reloadBlocks.length > 0) {
                    $reloadBlocks.each(function () {

                        var $block = $(this);
                        var block_id = $block.attr('id');

                        if (block_id) {
                            var $reloadElements = $block.find('.jsAjaxReloadItem');
                            var $newReloadElements = $(response).find('#' + block_id).find('.jsAjaxReloadItem');

                            var i = 0;
                            $reloadElements.each(function () {
                                var $element = $(this);
                                var $newElement = $($newReloadElements[i]);
                                i++;

                                if ($newElement) {
                                    console.log($newElement);

                                    if ($element.html() != $newElement.html()) {
                                        $element.after($newElement);
                                        $element.remove();

                                    }
                                } else {
                                    $element.remove();
                                }
                                if ($('.popup-anim').length != 0) {
                                    $('.popup-anim').magnificPopup({
                                        type: 'inline',
                                        removalDelay: 300,
                                        mainClass: 'my-mfp-zoom-in'
                                    });
                                    console.log($('.popup-anim'));
                                }

                            });

                            while (i < $newReloadElements.length) {
                                $block.append($($newReloadElements[i]));
                                i++;
                            }
                        }
                    });
                }
            }
        });
    }

    delete $reloadBlocks;
    delete $reloadBlocksChat;


}
