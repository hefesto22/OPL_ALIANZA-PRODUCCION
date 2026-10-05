<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo oficial de departamentos y municipios de Honduras.
 *
 * Para qué: los clientes del EDT se ubican por departamento y municipio, y
 * en la fase 4 cada vendedor tendrá asignadas sus zonas. Si la zona fuera
 * texto libre, "STA ROSA" y "SANTA ROSA DE COPÁN" serían dos zonas distintas
 * y el vendedor no vería a la mitad de sus clientes. Por eso es catálogo.
 *
 * Datos: 18 departamentos y 298 municipios con su código INE (departamento
 * de 2 dígitos + municipio de 2 dígitos: 0401 = Santa Rosa de Copán). Van
 * DENTRO de la migración a propósito: cada entorno los recibe con
 * `migrate` y una corrección futura se hace con otra migración (update por
 * código), sin depender de correr un seeder aparte.
 *
 * Tablas con prefijo hn_ y no edt_: es geografía del país, no lógica del
 * EDT. Nada de Jaremar la usa (sus facturas traen departamento y municipio
 * como texto propio y no se tocan).
 *
 * unique(id, department_id) en municipios existe para que edt_clients
 * pueda tener una FK compuesta (municipality_id, department_id): así la BD
 * no acepta un municipio de otro departamento.
 */
return new class extends Migration
{
    /**
     * Código INE del departamento => [nombre, [código INE del municipio => nombre]].
     *
     * @var array<string, array{0: string, 1: array<string, string>}>
     */
    private const DEPARTMENTS = [
        '01' => ['ATLÁNTIDA', [
            '0101' => 'LA CEIBA', '0102' => 'EL PORVENIR', '0103' => 'ESPARTA', '0104' => 'JUTIAPA',
            '0105' => 'LA MASICA', '0106' => 'SAN FRANCISCO', '0107' => 'TELA', '0108' => 'ARIZONA',
        ]],
        '02' => ['COLÓN', [
            '0201' => 'TRUJILLO', '0202' => 'BALFATE', '0203' => 'IRIONA', '0204' => 'LIMÓN', '0205' => 'SABÁ',
            '0206' => 'SANTA FE', '0207' => 'SANTA ROSA DE AGUÁN', '0208' => 'SONAGUERA', '0209' => 'TOCOA',
            '0210' => 'BONITO ORIENTAL',
        ]],
        '03' => ['COMAYAGUA', [
            '0301' => 'COMAYAGUA', '0302' => 'AJUTERIQUE', '0303' => 'EL ROSARIO', '0304' => 'ESQUÍAS',
            '0305' => 'HUMUYA', '0306' => 'LA LIBERTAD', '0307' => 'LAMANÍ', '0308' => 'LA TRINIDAD',
            '0309' => 'LEJAMANÍ', '0310' => 'MEÁMBAR', '0311' => 'MINAS DE ORO', '0312' => 'OJO DE AGUA',
            '0313' => 'SAN JERÓNIMO', '0314' => 'SAN JOSÉ DE COMAYAGUA', '0315' => 'SAN JOSÉ DEL POTRERO',
            '0316' => 'SAN LUIS', '0317' => 'SAN SEBASTIÁN', '0318' => 'SIGUATEPEQUE',
            '0319' => 'VILLA DE SAN ANTONIO', '0320' => 'LAS LAJAS', '0321' => 'TAULABÉ',
        ]],
        '04' => ['COPÁN', [
            '0401' => 'SANTA ROSA DE COPÁN', '0402' => 'CABAÑAS', '0403' => 'CONCEPCIÓN', '0404' => 'COPÁN RUINAS',
            '0405' => 'CORQUÍN', '0406' => 'CUCUYAGUA', '0407' => 'DOLORES', '0408' => 'DULCE NOMBRE',
            '0409' => 'EL PARAÍSO', '0410' => 'FLORIDA', '0411' => 'LA JIGUA', '0412' => 'LA UNIÓN',
            '0413' => 'NUEVA ARCADIA', '0414' => 'SAN AGUSTÍN', '0415' => 'SAN ANTONIO', '0416' => 'SAN JERÓNIMO',
            '0417' => 'SAN JOSÉ', '0418' => 'SAN JUAN DE OPOA', '0419' => 'SAN NICOLÁS', '0420' => 'SAN PEDRO',
            '0421' => 'SANTA RITA', '0422' => 'TRINIDAD DE COPÁN', '0423' => 'VERACRUZ',
        ]],
        '05' => ['CORTÉS', [
            '0501' => 'SAN PEDRO SULA', '0502' => 'CHOLOMA', '0503' => 'OMOA', '0504' => 'PIMIENTA',
            '0505' => 'POTRERILLOS', '0506' => 'PUERTO CORTÉS', '0507' => 'SAN ANTONIO DE CORTÉS',
            '0508' => 'SAN FRANCISCO DE YOJOA', '0509' => 'SAN MANUEL', '0510' => 'SANTA CRUZ DE YOJOA',
            '0511' => 'VILLANUEVA', '0512' => 'LA LIMA',
        ]],
        '06' => ['CHOLUTECA', [
            '0601' => 'CHOLUTECA', '0602' => 'APACILAGUA', '0603' => 'CONCEPCIÓN DE MARÍA', '0604' => 'DUYURE',
            '0605' => 'EL CORPUS', '0606' => 'EL TRIUNFO', '0607' => 'MARCOVIA', '0608' => 'MOROLICA',
            '0609' => 'NAMASIGÜE', '0610' => 'OROCUINA', '0611' => 'PESPIRE', '0612' => 'SAN ANTONIO DE FLORES',
            '0613' => 'SAN ISIDRO', '0614' => 'SAN JOSÉ', '0615' => 'SAN MARCOS DE COLÓN',
            '0616' => 'SANTA ANA DE YUSGUARE',
        ]],
        '07' => ['EL PARAÍSO', [
            '0701' => 'YUSCARÁN', '0702' => 'ALAUCA', '0703' => 'DANLÍ', '0704' => 'EL PARAÍSO', '0705' => 'GÜINOPE',
            '0706' => 'JACALEAPA', '0707' => 'LIURE', '0708' => 'MOROCELÍ', '0709' => 'OROPOLÍ',
            '0710' => 'POTRERILLOS', '0711' => 'SAN ANTONIO DE FLORES', '0712' => 'SAN LUCAS',
            '0713' => 'SAN MATÍAS', '0714' => 'SOLEDAD', '0715' => 'TEUPASENTI', '0716' => 'TEXIGUAT',
            '0717' => 'VADO ANCHO', '0718' => 'YAUYUPE', '0719' => 'TROJES',
        ]],
        '08' => ['FRANCISCO MORAZÁN', [
            '0801' => 'DISTRITO CENTRAL', '0802' => 'ALUBARÉN', '0803' => 'CEDROS', '0804' => 'CURARÉN',
            '0805' => 'EL PORVENIR', '0806' => 'GUAIMACA', '0807' => 'LA LIBERTAD', '0808' => 'LA VENTA',
            '0809' => 'LEPATERIQUE', '0810' => 'MARAITA', '0811' => 'MARALE', '0812' => 'NUEVA ARMENIA',
            '0813' => 'OJOJONA', '0814' => 'ORICA', '0815' => 'REITOCA', '0816' => 'SABANAGRANDE',
            '0817' => 'SAN ANTONIO DE ORIENTE', '0818' => 'SAN BUENAVENTURA', '0819' => 'SAN IGNACIO',
            '0820' => 'SAN JUAN DE FLORES', '0821' => 'SAN MIGUELITO', '0822' => 'SANTA ANA',
            '0823' => 'SANTA LUCÍA', '0824' => 'TALANGA', '0825' => 'TATUMBLA', '0826' => 'VALLE DE ÁNGELES',
            '0827' => 'VILLA DE SAN FRANCISCO', '0828' => 'VALLECILLO',
        ]],
        '09' => ['GRACIAS A DIOS', [
            '0901' => 'PUERTO LEMPIRA', '0902' => 'BRUS LAGUNA', '0903' => 'AHUAS', '0904' => 'JUAN FRANCISCO BULNES',
            '0905' => 'RAMÓN VILLEDA MORALES', '0906' => 'WAMPUSIRPI',
        ]],
        '10' => ['INTIBUCÁ', [
            '1001' => 'LA ESPERANZA', '1002' => 'CAMASCA', '1003' => 'COLOMONCAGUA', '1004' => 'CONCEPCIÓN',
            '1005' => 'DOLORES', '1006' => 'INTIBUCÁ', '1007' => 'JESÚS DE OTORO', '1008' => 'MAGDALENA',
            '1009' => 'MASAGUARA', '1010' => 'SAN ANTONIO', '1011' => 'SAN ISIDRO', '1012' => 'SAN JUAN',
            '1013' => 'SAN MARCOS DE LA SIERRA', '1014' => 'SAN MIGUEL GUANCAPLA', '1015' => 'SANTA LUCÍA',
            '1016' => 'YAMARANGUILA', '1017' => 'SAN FRANCISCO DE OPALACA',
        ]],
        '11' => ['ISLAS DE LA BAHÍA', [
            '1101' => 'ROATÁN', '1102' => 'GUANAJA', '1103' => 'JOSÉ SANTOS GUARDIOLA', '1104' => 'UTILA',
        ]],
        '12' => ['LA PAZ', [
            '1201' => 'LA PAZ', '1202' => 'AGUANQUETERIQUE', '1203' => 'CABAÑAS', '1204' => 'CANE',
            '1205' => 'CHINACLA', '1206' => 'GUAJIQUIRO', '1207' => 'LAUTERIQUE', '1208' => 'MARCALA',
            '1209' => 'MERCEDES DE ORIENTE', '1210' => 'OPATORO', '1211' => 'SAN ANTONIO DEL NORTE',
            '1212' => 'SAN JOSÉ', '1213' => 'SAN JUAN', '1214' => 'SAN PEDRO DE TUTULE', '1215' => 'SANTA ANA',
            '1216' => 'SANTA ELENA', '1217' => 'SANTA MARÍA', '1218' => 'SANTIAGO DE PURINGLA', '1219' => 'YARULA',
        ]],
        '13' => ['LEMPIRA', [
            '1301' => 'GRACIAS', '1302' => 'BELÉN', '1303' => 'CANDELARIA', '1304' => 'COLOLACA',
            '1305' => 'ERANDIQUE', '1306' => 'GUALCINCE', '1307' => 'GUARITA', '1308' => 'LA CAMPA',
            '1309' => 'LA IGUALA', '1310' => 'LAS FLORES', '1311' => 'LA UNIÓN', '1312' => 'LA VIRTUD',
            '1313' => 'LEPAERA', '1314' => 'MAPULACA', '1315' => 'PIRAERA', '1316' => 'SAN ANDRÉS',
            '1317' => 'SAN FRANCISCO', '1318' => 'SAN JUAN GUARITA', '1319' => 'SAN MANUEL COLOHETE',
            '1320' => 'SAN RAFAEL', '1321' => 'SAN SEBASTIÁN', '1322' => 'SANTA CRUZ', '1323' => 'TALGUA',
            '1324' => 'TAMBLA', '1325' => 'TOMALÁ', '1326' => 'VALLADOLID', '1327' => 'VIRGINIA',
            '1328' => 'SAN MARCOS DE CAIQUÍN',
        ]],
        '14' => ['OCOTEPEQUE', [
            '1401' => 'NUEVA OCOTEPEQUE', '1402' => 'BELÉN GUALCHO', '1403' => 'CONCEPCIÓN',
            '1404' => 'DOLORES MERENDÓN', '1405' => 'FRATERNIDAD', '1406' => 'LA ENCARNACIÓN', '1407' => 'LA LABOR',
            '1408' => 'LUCERNA', '1409' => 'MERCEDES', '1410' => 'SAN FERNANDO', '1411' => 'SAN FRANCISCO DEL VALLE',
            '1412' => 'SAN JORGE', '1413' => 'SAN MARCOS', '1414' => 'SANTA FE', '1415' => 'SENSENTI',
            '1416' => 'SINUAPA',
        ]],
        '15' => ['OLANCHO', [
            '1501' => 'JUTICALPA', '1502' => 'CAMPAMENTO', '1503' => 'CATACAMAS', '1504' => 'CONCORDIA',
            '1505' => 'DULCE NOMBRE DE CULMÍ', '1506' => 'EL ROSARIO', '1507' => 'ESQUIPULAS DEL NORTE',
            '1508' => 'GUALACO', '1509' => 'GUARIZAMA', '1510' => 'GUATA', '1511' => 'GUAYAPE', '1512' => 'JANO',
            '1513' => 'LA UNIÓN', '1514' => 'MANGULILE', '1515' => 'MANTO', '1516' => 'SALAMÁ',
            '1517' => 'SAN ESTEBAN', '1518' => 'SAN FRANCISCO DE BECERRA', '1519' => 'SAN FRANCISCO DE LA PAZ',
            '1520' => 'SANTA MARÍA DEL REAL', '1521' => 'SILCA', '1522' => 'YOCÓN', '1523' => 'PATUCA',
        ]],
        '16' => ['SANTA BÁRBARA', [
            '1601' => 'SANTA BÁRBARA', '1602' => 'ARADA', '1603' => 'ATIMA', '1604' => 'AZACUALPA',
            '1605' => 'CEGUACA', '1606' => 'SAN JOSÉ DE COLINAS', '1607' => 'CONCEPCIÓN DEL NORTE',
            '1608' => 'CONCEPCIÓN DEL SUR', '1609' => 'CHINDA', '1610' => 'EL NÍSPERO', '1611' => 'GUALALA',
            '1612' => 'ILAMA', '1613' => 'MACUELIZO', '1614' => 'NARANJITO', '1615' => 'NUEVO CELILAC',
            '1616' => 'PETOA', '1617' => 'PROTECCIÓN', '1618' => 'QUIMISTÁN', '1619' => 'SAN FRANCISCO DE OJUERA',
            '1620' => 'SAN LUIS', '1621' => 'SAN MARCOS', '1622' => 'SAN NICOLÁS', '1623' => 'SAN PEDRO ZACAPA',
            '1624' => 'SANTA RITA', '1625' => 'SAN VICENTE CENTENARIO', '1626' => 'TRINIDAD', '1627' => 'LAS VEGAS',
            '1628' => 'NUEVA FRONTERA',
        ]],
        '17' => ['VALLE', [
            '1701' => 'NACAOME', '1702' => 'ALIANZA', '1703' => 'AMAPALA', '1704' => 'ARAMECINA', '1705' => 'CARIDAD',
            '1706' => 'GOASCORÁN', '1707' => 'LANGUE', '1708' => 'SAN FRANCISCO DE CORAY', '1709' => 'SAN LORENZO',
        ]],
        '18' => ['YORO', [
            '1801' => 'YORO', '1802' => 'ARENAL', '1803' => 'EL NEGRITO', '1804' => 'EL PROGRESO', '1805' => 'JOCÓN',
            '1806' => 'MORAZÁN', '1807' => 'OLANCHITO', '1808' => 'SANTA RITA', '1809' => 'SULACO',
            '1810' => 'VICTORIA', '1811' => 'YORITO',
        ]],
    ];

    public function up(): void
    {
        Schema::create('hn_departments', function (Blueprint $table) {
            $table->id();
            $table->char('code', 2)->unique();
            $table->string('name', 40)->unique();
            $table->timestamps();
        });

        Schema::create('hn_municipalities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('hn_departments')->restrictOnDelete();
            $table->char('code', 4)->unique();
            $table->string('name', 60);
            $table->timestamps();

            // También sirve de índice de la FK department_id (columna líder)
            // y para listar los municipios de un departamento por nombre.
            $table->unique(['department_id', 'name'], 'hn_municipalities_department_name_unique');
            // Destino de la FK compuesta de edt_clients (municipio + su departamento).
            $table->unique(['id', 'department_id'], 'hn_municipalities_id_department_unique');
        });

        DB::statement("ALTER TABLE hn_departments ADD CONSTRAINT hn_departments_code_format CHECK (code ~ '^[0-9]{2}$')");
        DB::statement("ALTER TABLE hn_municipalities ADD CONSTRAINT hn_municipalities_code_format CHECK (code ~ '^[0-9]{4}$')");

        $now = now();

        foreach (self::DEPARTMENTS as $departmentCode => [$departmentName, $municipalities]) {
            $departmentId = DB::table('hn_departments')->insertGetId([
                // PHP convierte las llaves '10'…'18' (y '1001'…) en enteros: se vuelven a texto.
                'code' => (string) $departmentCode,
                'name' => $departmentName,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $rows = [];
            foreach ($municipalities as $municipalityCode => $municipalityName) {
                $rows[] = [
                    'department_id' => $departmentId,
                    'code' => (string) $municipalityCode,
                    'name' => $municipalityName,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('hn_municipalities')->insert($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hn_municipalities');
        Schema::dropIfExists('hn_departments');
    }
};
