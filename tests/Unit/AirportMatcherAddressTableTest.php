<?php

namespace Tests\Unit;

use App\Support\AirportMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Real-world addresses through the one airport matcher. A false positive here
 * charges meet & greet on a home pickup (the Sprotbrough → EMA £190 incident), puts
 * the wrong airport in the calendar title, or rotates the wrong driver; a false
 * negative drops meet & greet / flight tracking on a genuine arrival.
 */
class AirportMatcherAddressTableTest extends TestCase
{
    /** @return array<string, array{0: string, 1: bool, 2: ?string}> address, isAirport, codeFor */
    public static function addresses(): array
    {
        return [
            // Doncaster homes — never the (closed) Doncaster Sheffield airport.
            'Sprotbrough home' => ['28a Park Drive, Sprotbrough, Doncaster DN5 7LA', false, null],
            'Bessacarr home' => ['14 Bawtry Road, Bessacarr, Doncaster DN4 7AB', false, null],
            'Armthorpe home' => ['3 Church Street, Armthorpe, Doncaster DN3 3AE', false, null],
            'Doncaster town' => ['Frenchgate Centre, Doncaster', false, null],
            'Doncaster Sheffield Airport' => ['Doncaster Sheffield Airport, Heyford House, First Avenue, Doncaster DN9 3RH', true, 'DSA'],
            'Robin Hood Airport' => ['Robin Hood Airport, Doncaster', true, 'DSA'],
            'Terminal, Doncaster' => ['Terminal Building, Doncaster', false, null],

            // Manchester.
            'Manchester Piccadilly station' => ['Manchester Piccadilly Station, Manchester M1 2BN', false, null],
            'Manchester city hotel' => ['The Midland Hotel, 16 Peter Street, Manchester M60 2DS', false, null],
            'Manchester Airport T2' => ['Manchester Airport T2', true, 'MAN'],
            'Terminal 2, Manchester' => ['Terminal 2, Manchester', true, 'MAN'],
            'Manchester Airport postcode' => ['Manchester M90 1QX', true, 'MAN'],

            // Luton.
            'Luton town centre' => ['10 George Street, Luton LU1 2AF', false, null],
            'Luton LU2 home' => ['22 Stopsley Way, Luton LU2 7UR', false, null],
            'London Luton Airport' => ['London Luton Airport, Airport Way, Luton LU2 9LY', true, 'LTN'],
            'Luton Airport postcode' => ['Airport Way, Luton LU2 9LY', true, 'LTN'],

            // Gatwick.
            'Gatwick Road Crawley' => ['Gatwick Road, Crawley RH10 9RD', false, null],
            'Horley home RH6' => ['8 Victoria Road, Horley RH6 7AA', false, null],
            'Gatwick South Terminal' => ['Gatwick South Terminal', true, 'LGW'],
            'North Terminal, Crawley' => ['North Terminal, Crawley', true, 'LGW'],

            // Birmingham.
            'Birmingham New Street' => ['Birmingham New Street Station, Birmingham B2 4QA', false, null],
            'Sheldon home B26' => ['40 Coventry Road, Sheldon, Birmingham B26 3EH', false, null],
            'Birmingham Airport' => ['Birmingham Airport, Birmingham B26 3QJ', true, 'BHX'],

            // Leeds.
            'Leeds city centre' => ['The Queens Hotel, City Square, Leeds LS1 1PJ', false, null],
            'Yeadon home LS19' => ['5 High Street, Yeadon, Leeds LS19 7SP', false, null],
            'Leeds Bradford Airport' => ['Leeds Bradford Airport, Whitehouse Lane, Yeadon LS19 7TU', true, 'LBA'],
            'Leeds Bradford Road' => ['120 Leeds Bradford Road, Pudsey', false, null],

            // London.
            'Liverpool Street London' => ['Liverpool Street Station, London EC2M 7QH', false, null],
            'Heathrow Terminal 5' => ['Heathrow Terminal 5', true, 'LHR'],

            // East Midlands.
            'East Midlands Parkway station' => ['East Midlands Parkway, Ratcliffe-on-Soar, Nottingham NG11 0EE', false, null],
            'East Midlands Airport' => ['East Midlands Airport, Castle Donington DE74 2SA', true, 'EMA'],
            'EMA postcode only' => ['Castle Donington, Derby DE74 2SA', true, 'EMA'],
            'DE74 home (Kegworth)' => ['12 High Street, Kegworth, Derby DE74 2DA', false, null],
            'Castle Donington without airport' => ['3 Borough Street, Castle Donington, Derby DE74 2LA', false, null],

            // Office buildings, streets and estates named after airports/terminals.
            'Terminal House, Sheffield' => ['Terminal House, Shepcote Lane, Sheffield S9 1TT', false, null],
            'Airport Road' => ['15 Airport Road, Belfast BT3 9DY', false, null],
            'Airport Retail Park' => ['Airport Retail Park, Coventry CV3 4FJ', false, null],
            'Airport Business Park' => ['Unit 4, Airport Business Park, Southend SS2 6YF', false, null],
            'Bus terminal' => ['Bus Terminal, Doncaster Interchange', false, null],

            // Stansted village vs the airport.
            'Stansted Mountfitchet home' => ['2 Lower Street, Stansted Mountfitchet CM24 8LP', false, null],
            'London Stansted Airport' => ['London Stansted Airport, Bassingbourn Road, Stansted CM24 1QW', true, 'STN'],

            // Private-jet handlers / FBOs and airport hotels count as the airport
            // (Kerry's private flights land at the EMA flight-support cabin).
            'EMA flight support (Kerry)' => ['Advantage Flight Support Ltd, Derby DE74 2SA, UK', true, 'EMA'],
            'EMA flight support, no postcode' => ['Advantage Flight Support, Castle Donington', true, 'EMA'],
            'Luton FBO' => ['Harrods Aviation, Luton LU2 9PA', true, 'LTN'],
            'Birmingham FBO' => ['Signature Aviation, Birmingham B26 3QN', true, 'BHX'],
            'Kegworth hangar' => ['Hangar 5, Kegworth', true, 'EMA'],
            'EMA airport hotel' => ['Radisson Blu Hotel, East Midlands Airport, DE74 2SH', true, 'EMA'],
            'MAN airport hotel' => ['Hilton Manchester Airport, Outwood Lane', true, 'MAN'],
            'Aviation office in town' => ['Aviation House, Manchester M1 2AB', false, null],
            'Castle Donington hotel (not the airport)' => ['Premier Inn Castle Donington DE74 2TU', false, null],
        ];
    }

    #[DataProvider('addresses')]
    public function test_address(string $address, bool $isAirport, ?string $code): void
    {
        $this->assertSame($isAirport, AirportMatcher::isAirport($address), "isAirport({$address})");
        $this->assertSame($code, AirportMatcher::codeFor($address), "codeFor({$address})");
    }

    public function test_airport_hotels_and_jet_handlers_are_sites_not_the_terminal(): void
    {
        // Charged as an airport pickup, but no "name board in arrivals" wording.
        $this->assertTrue(AirportMatcher::isAirportSite('Advantage Flight Support Ltd, Derby DE74 2SA, UK'));
        $this->assertTrue(AirportMatcher::isAirportSite('Radisson Blu Hotel, East Midlands Airport, DE74 2SH'));
        $this->assertTrue(AirportMatcher::isAirportSite('Hilton Manchester Airport, Outwood Lane'));
        $this->assertTrue(AirportMatcher::isAirportSite('Harrods Aviation, Luton LU2 9PA'));

        // The terminal itself is a normal meet & greet.
        $this->assertFalse(AirportMatcher::isAirportSite('Manchester Airport Terminal 2'));
        $this->assertFalse(AirportMatcher::isAirportSite('East Midlands Airport (EMA), Castle Donington, Derby, UK'));
        $this->assertFalse(AirportMatcher::isAirportSite('Heathrow Terminal 5'));
        // Not an airport at all.
        $this->assertFalse(AirportMatcher::isAirportSite('Premier Inn Castle Donington DE74 2TU'));
    }

    public function test_the_incident_route_is_a_departure_not_an_arrival(): void
    {
        // Sprotbrough → EMA: the pickup is a home, the drop-off is the airport.
        $this->assertFalse(AirportMatcher::isAirport('28a Park Drive, Sprotbrough, Doncaster DN5 7LA'));
        $this->assertTrue(AirportMatcher::isAirport('East Midlands Airport (EMA)'));
        $this->assertSame('EMA', AirportMatcher::codeFor('28a Park Drive, Sprotbrough, Doncaster DN5 7LA', 'East Midlands Airport (EMA)'));
    }
}
