<?php
namespace EPFL\Plugins\Gutenberg\InfoscienceSearch;

require_once('File/MARCXML.php');

function checkXMLValidity($content)  // see https://stackoverflow.com/a/31240779
{
    $content = trim($content);
    if (empty($content)) {
        throw new InfoscienceUnknownContentException("The data provided are empty.");
    }
    //html go to hell!
    if (stripos($content, '<!DOCTYPE html>') !== false) {
        throw new InfoscienceUnknownContentException("The data provided is recognised as HTML, despite we need XML.");
    }

    libxml_use_internal_errors(true);
    simplexml_load_string($content);
    $errors = libxml_get_errors();
    libxml_clear_errors();

    if (!empty($errors)) {
        throw new InfoscienceUnknownContentException("The data provided can not be parsed as XMl data.");
    }
}

Class InfoscienceMarcConverter
{

    /**
    * Parse all entries and drop them 'as is'
    */
    public static function parse_all($record, $field) {
        $list_fields = [];

        foreach ($record->getFields($field) as $tag => $subfields) {
            if (method_exists($subfields, 'getSubfields')) {
                foreach ($subfields->getSubfields() as $code => $value) {
                    $list_fields[$tag] = $value;
                }
            }
        }

        return $list_fields;
    }

    /**
    * Parse all urls and dispatch them into
    * urls => icon, fulltext
    */
    public static function parse_files($record, $field) {
        $file_urls  = InfoscienceMarcConverter::parse_text($record, '856', '4', '', ['u', '0']);
        $sorted_urls = [];

        foreach($file_urls as $url_and_type) {
            $url = $url_and_type[0];
            $type = null;

            if (count($url_and_type) > 1) {
                $type = $url_and_type[1];
            }

            $url = preg_replace("(^https?://)", "//", $url);

            if (preg_match('/\.pdf$/', strtolower($url))) {
                if (!array_key_exists('fulltext', $sorted_urls)) {
                    $sorted_urls['fulltext'] = [];
                }

                # set first position for thesis document
                if (preg_match('/epfl_th\d+\.pdf$/', strtolower($url))) {
                    array_unshift($sorted_urls['fulltext'], $url);
                } else {
                    $sorted_urls['fulltext'][] = $url;
                }
            } else {
                $matches = [];
                preg_match('/(\.png|\.jpg|\.jpeg|\.gif)$/', $url, $matches);
                if ($matches && strcasecmp($type,'Thumbnail') === 0) {  // insensitive value check
                    $sorted_urls['icon'][] = $url;
                }
            }
        }
        return $sorted_urls;
    }

    /**
    * Parse external ids and filter to get only DOIs
    */
    public static function parse_doi($record) {
        $dois = [];
        // get the value and the identifier
        $fields_0247 = InfoscienceMarcConverter::parse_text($record, '024', '7', '', ['a', '2']);

        foreach ($fields_0247 as $field) {
            # get the id name, the 0247_2
            if (count($field) > 1) {
                if (strtolower($field[1]) === 'doi')
                {
                    $dois[] = $field[0];
                }
            }
        }

        return $dois;
    }

    /**
     * Parse a text and sanitize the url
     */
    public static function parse_url($record, $field, $ind1='', $ind2='', $subfields=[''], $subfields_name = []) {
        $url = InfoscienceMarcConverter::parse_text($record, '790', '', '', ['w']);

        if ($url && !empty($url)) {
            $url = preg_replace("(^https?://)", "//", $url);
        }

        return $url;
    }

    /**
    * Parse a specified entry. Provide multiple subfields with name to have a key value return
    */
    public static function parse_text($record, $field, $ind1='', $ind2='', $subfields=[''], $subfields_name = []) {
         if (!$subfields[0]){
            return [$record->getField($field)->getData()];
         }

         $fields = $record->getFields($field);
         $value = [];
         $sub_values_mode = false;

         if (count($subfields) > 1) {
            $sub_values_mode = true;
         }

        foreach ($fields as $field) {
            $sub_value = [];
            foreach($subfields as $index=>$subfield) {
                if ($subfield === '0' || !empty($subfield)) {
                    if ($field->getSubfield($subfield)) {
                        if ($subfields_name && array_key_exists($index, $subfields_name)) {
                            $sub_value[$subfields_name[$index]] = $field->getSubfield($subfield)->getData();
                        } else {
                            $sub_value[] = $field->getSubfield($subfield)->getData();
                        }
                    }
                }
            }
            if ($sub_value) {
                if ($sub_values_mode) {
                    $value[] = $sub_value;
                } else {
                    $value = $sub_value;
                }
            }
        }
        return $value;
    }

    public static function parse_authors_partially($record, $field, $ind1, $ind2, $subfields) {
        $build_search_url = function ($full_name) {
            $full_name = str_replace(' ', '+', $full_name);
            $full_name = str_replace(',', '+', $full_name);
            return "https://infoscience.epfl.ch/search?p=" . $full_name;
        };

        $compute_name = function ($full_name) {
            $names = explode(',', $full_name);
            $family = count($names) > 0 ? trim($names[0]) : '';
            $fnames = count($names) > 1 ? explode(' ', $names[1]) : '';

            $initname = "";

            if (is_array($fnames)) {
                foreach($fnames as $fname) {
                    if (!$fname || empty($fname)) {
                        continue;
                    }

                    $fname = trim($fname);

                    if (strpos($fname, '-') !== false) {
                        $sname = explode('-', $fname);

                        if (mb_strlen($sname[0]) > 1) {
                            $initname .= mb_substr($sname[0], 0, 1);
                        }

                        if (mb_strlen($sname[0]) > 1 || mb_strlen($sname[1]) > 1) {
                            $initname .= "-";
                        }

                        if (mb_strlen($sname[1]) > 1) {
                            $initname .= mb_substr($sname[1], 0, 1) . ". ";
                        }
                    }
                    else {
                        $fname = trim($fname);

                        if (!empty($fname)) {
                            $initname .= trim(mb_substr($fname, 0, 1)) . ". ";
                        }
                    }
                }
            }

            if ($family && !empty($family)) {
                $initname .= $family;
            }

            return $initname;
        };


        $authors = [];
        $people = $record->getFields($field);
        $subfield = $subfields[0];
        $iterated_authors = 0;  # as we only need the first authors

        if ($people && $subfield) {
            foreach ($people as $person) {
                # as we only need the first 5 authors to know if we are in a 4+ scenario
                if ($iterated_authors > 5) break;

                if (!$person->isEmpty()) {
                    $person_data = [];
                    $full_name = "";
                    $person_subfield = $person->getSubfield($subfield);

                    if (!$person_subfield) {
                        continue;
                    }

                    # if we have an indicator, verify that the person in the right one
                    if ($ind1) {
                        $indicator = $person->getIndicator(1);

                        if ($indicator == $ind1) {
                            $full_name = $person_subfield->getData();
                            $person_data['initial_name'] = $compute_name($full_name);
                        }
                    } elseif ($ind2) {
                        $indicator = $person->getIndicator(2);

                        if ($indicator == $ind2) {
                            $full_name = $person_subfield->getData();
                            $person_data['initial_name'] = $compute_name($full_name);
                        }
                    } else {
                        $full_name = $person_subfield->getData();
                        $person_data['initial_name'] = $compute_name($full_name);
                    }

                    if (array_key_exists('initial_name', $person_data) && !empty($person_data['initial_name']))
                    {
                        # add the computed search_url data
                        $person_data['search_url'] = $build_search_url($full_name);
                    }


                    $authors[] = $person_data;
                    $iterated_authors++;
                }
            }
        }

        return $authors;
    }

    /**
     * Transform Marc record to a flat key value array
     * @param $filter_empy: for debug purpose, if you want to see all the field, empty or not
     */
    public static function parse_record($record, $filter_empty=false) {
        $record_array = [];

        $record_array['record_id'] = InfoscienceMarcConverter::parse_text($record, '001', '', '', ['']);

        # SPEC: how we show it ?
        $record_array['patent'] = InfoscienceMarcConverter::parse_text($record, '013', '', '',['a', 'c'], ['number', 'state']);

        $record_array['isbn'] = InfoscienceMarcConverter::parse_text($record, '020', '', '', ['a']);

        # SPEC: don't get doi if patents, as 0247_a has the TTO id too
        $record_array['doi'] = InfoscienceMarcConverter::parse_doi($record);

        $record_array['title'] = InfoscienceMarcConverter::parse_text($record, '245', '', '', ['a']);

        $record_array['publication_location'] = InfoscienceMarcConverter::parse_text($record, '260', '', '', ['a']);
        $record_array['publication_institution'] = InfoscienceMarcConverter::parse_text($record, '260', '', '', ['b']);
        $record_array['publication_date'] = InfoscienceMarcConverter::parse_text($record, '269', '', '', ['a']);
        $record_array['publication_page'] = InfoscienceMarcConverter::parse_text($record, '300', '', '', ['a']);

        /* if needed, uncomment this generic datas
        $record_array['description'] = InfoscienceMarcConverter::parse_all($record, '300');
        */

        /* if needed, uncomment this generic datas
        $record_array['subjects'] = InfoscienceMarcConverter::parse_all($record, ['600', '610', '611', '630', '648', '650',
            '651', '653', '654', '655', '656', '657', '658', '662', '690',
            '691', '696', '697', '698', '699']);
        */

        $record_array['doctype'] = InfoscienceMarcConverter::parse_text($record, '336', '', '', ['a']);

        $record_array['summary'] = InfoscienceMarcConverter::parse_text($record, '520', '', '', ['a']);

        $record_array['author'] = InfoscienceMarcConverter::parse_authors_partially($record, '700', '', '', ['a']);

        $record_array['corporate_name'] = InfoscienceMarcConverter::parse_text($record, '710', '', '', ['a']);

        $record_array['conference'] = InfoscienceMarcConverter::parse_text($record, '711', '', '', ['a', 'c', 'd'], ['name', 'location', 'date']);
        if (empty($record_array['conference'])) {
            $record_array['conference'] = InfoscienceMarcConverter::parse_text($record, '711', '2', '', ['a', 'c', 'd'], ['name', 'location', 'date']);
        }

        $record_array['author_1'] = InfoscienceMarcConverter::parse_authors_partially($record, '720', '', '1', ['a']);
        $record_array['director'] = InfoscienceMarcConverter::parse_authors_partially($record, '720', '', '2', ['a']);
        $record_array['author_3'] = InfoscienceMarcConverter::parse_authors_partially($record, '720', '', '3', ['a']);

        $record_array['company_name'] = InfoscienceMarcConverter::parse_text($record, '720', '', '5', ['a']);

        $record_array['journal'] = InfoscienceMarcConverter::parse_text($record, '773', '', '5', ['j', 'k', 'q', 't'], ['volume', 'number', 'page', 'publisher']);

        $record_array['report_url'] = InfoscienceMarcConverter::parse_url($record, '790', '', '', ['w']);

        # TODO: url has special rules, set url if fulltexts / icons to print
        $record_array['url'] = InfoscienceMarcConverter::parse_files($record, '856', '4', '', ['u']);

        $record_array['approved'] = InfoscienceMarcConverter::parse_text($record, '909', 'C', '0', ['p']);
        $record_array['pending'] = InfoscienceMarcConverter::parse_text($record, '999', 'C', '0', ['p']);

        if ($filter_empty) {
            $record_array = array_filter($record_array);
        }

        return $record_array;
    }

    /**
     * Transform Marc from Infoscience into an multiple dimension array
     *
     * @param $marc_xml: response of infoscience search in of=xm format
     * @return the built array
     */
    public static function convert_marc_to_array($marc_xml) {
        try {
            $publications = [];

            checkXMLValidity($marc_xml);

            $marc_source = new \File_MARCXML($marc_xml, \File_MARC::SOURCE_STRING);

            while ($marc_record = $marc_source->next()) {
                $publications[] = InfoscienceMarcConverter::parse_record($marc_record, false);
            }

            return $publications;
        }
        catch (File_MARC_Exception $e) {
            throw new InfoscienceUnknownContentException("The data provided are not XMLMARC compatible, despite looking as XML.");
        }
    }
}
?>
